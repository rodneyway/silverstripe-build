<?php

/**
 * A phing task to perform a deployment of a specified
 * tarball to a remote server
 *
 * @author marcus@symbiote.com.au
 * @license BSD License http://silverstripe.org/bsd-license/
 */
class SilverStripeDeployTask extends SilverStripeBuildTask {
	/* deployment config */

	private $localpath;
	private $apppath;
	private $package = '';
	private $apachegroup = 'apache';
	private $remotepath = '';
	private $incremental = false;
	
	private $sapphirepath = 'framework';

	/* SSH configuration */
	private $host = "";
	private $port = 22;
	private $username = "";
	private $password = "";
	private $pubkeyfile = '';
	private $privkeyfile = '';
	private $privkeyfilepassphrase = '';
	private $ignoreerrors = false;
	// are we updating the /current release in-place, or creating a new release?
	private $inplace = false;

	public function main() {

		if (!strlen($this->pubkeyfile) && !strlen($this->password)) {
			// prompt for the password
			$this->password = $this->getInput("Password for " . $this->username . '@' . $this->host);
		}

		$this->log("Connecting to $this->host");
		$this->connect();

		$currentPath = $this->remotepath . '/current';
		$releasePath = $this->configureReleaseDir();

		$remotePackage = $releasePath . '/' . $this->package;
		$localPackage = $this->localpath . '/' . $this->package;

		$this->log("Copying deployment package $localPackage");
		$this->copyFile($localPackage, $remotePackage);

		$this->beforeDeploy($releasePath, $currentPath);
		$this->extractPackage($remotePackage, $releasePath);
		$this->doDeploy($releasePath, $currentPath);
		$this->postDeploy($releasePath);

		@ssh2_exec($this->connection, 'exit');
	}

	/**
	 * Configure the release directory in the remote system
	 *
	 * @return string 
	 */
	protected function configureReleaseDir() {
		if ($this->inplace) {
			$this->log("WARNING: Setting remote path equal to current deployment");
			$releasePath = $this->remotepath . '/current';
		} else {
			$releasePath = $this->remotepath . '/releases/' . date('YmdHis');
			$this->log("Configuring target directories at $releasePath");
			$this->execute("mkdir --mode=2775 -p $releasePath/silverstripe-cache");
			$this->execute("mkdir --mode=2775 -p $releasePath/assets");
		}

		return $releasePath;
	}

	/**
	 * Copy the existing deployment and/or relevant configuration files
	 * to the new location
	 */
	protected function beforeDeploy($releasePath, $currentPath) {
		if ($this->incremental && !$this->inplace) {
			$this->log("Copying existing deployment");
			// we use rsync here to be able to use --excludes, and -l to preserve symlinks (ie. for assets folder)
			$this->execute("rsync -rl --exclude=silverstripe-cache $currentPath/* $releasePath/");

			$this->log("Copying configs");
			$this->execute("cp $currentPath/.htaccess $releasePath/");
			$this->execute("cp $currentPath/.env $releasePath/.env");

			// These are legacy, but remain to support older projects.

			$this->execute("cp $currentPath/_ss_environment.php $releasePath/");
			$this->execute("cp $currentPath/$this->apppath/local.conf.php $releasePath/$this->apppath/local.conf.php");

			// ---
			
			$localConf = "$currentPath/$this->apppath/_config/local.yml";
			$cmd = "if [ -f $localConf ]; then cp $localConf $releasePath/$this->apppath/_config/; fi";
			$this->execute($cmd);
		}
	}

	/**
	 * Extract the remote package
	 *
	 * @param type $remotePackage
	 * @param type $releasePath 
	 */
	protected function extractPackage($remotePackage, $releasePath) {
		$this->log("Extracting $remotePackage in $releasePath");
		$this->execute("tar -zx -C $releasePath -f $remotePackage");
		$this->execute("rm $remotePackage");
	}

	/**
	 * Update the new deployment with configs etc
	 *
	 * @param type $releasePath
	 * @param type $currentPath 
	 */
	protected function doDeploy($releasePath, $currentPath) {
		if (!$this->incremental && !$this->inplace) {
			$this->log("Copying configs");
			$this->execute("cp $currentPath/.htaccess $releasePath/");
			$this->execute("cp $currentPath/.env $releasePath/.env");

			// These are legacy, but remain to support older projects.

			$this->execute("cp $currentPath/_ss_environment.php $releasePath/");
			$this->execute("cp $currentPath/$this->apppath/local.conf.php $releasePath/$this->apppath/local.conf.php");

			// ---

			$localConf = "$currentPath/$this->apppath/_config/local.yml";
			$cmd = "if [ -f $localConf ]; then cp $localConf $releasePath/$this->apppath/_config/; fi";
			$this->execute($cmd);

			$this->log("Copying site assets");
			$this->execute("rsync -rl $currentPath/assets $releasePath/");
		}

		$this->log("Pre-deploy");
		$this->executeOptionalPhpScript($releasePath .'/'. $this->apppath .'/scripts/pre_deploy.php');

		$this->log("Backing up database");
		$this->execute("php $currentPath/$this->apppath/scripts/backup_database.php");
		
		$this->log("Saving .htaccess");
		$this->execute("cp $releasePath/.htaccess $releasePath/$this->apppath/.htaccess.bak");
		
		$this->log("Checking for maintenance mode, and switching if found");
		$maintenanceHtaccess = "$releasePath/$this->apppath/.htaccess-maintenance";
		$cmd = "if [ -f $maintenanceHtaccess ]; then cp $maintenanceHtaccess $currentPath/.htaccess; fi";
		$this->execute($cmd);
		$cmd = "if [ -f $maintenanceHtaccess ]; then cp $maintenanceHtaccess $releasePath/.htaccess; fi";
		
		$this->log($cmd);
		$this->execute($cmd);

		$this->log("Executing dev/build");
		// $this->execute("php $releasePath/$this->sapphirepath/cli-script.php dev/build");

		if (!$this->inplace) {
			$this->preLinkSwitch($releasePath);
			$this->log("Changing symlinks");
			$this->execute("rm $currentPath");
			$this->execute("ln -s $releasePath $currentPath");
		}

		$this->log("Restoring .htaccess");
		$htaccessBak = "$releasePath/$this->apppath/.htaccess.bak";
		$cmd = "if [ -f $htaccessBak ]; then cp $htaccessBak $releasePath/.htaccess; fi";
		$this->execute($cmd);

		$this->log("Finalising deployment");
		$this->execute("touch $releasePath/DEPLOYED");

	}

	/**
	 * If the project has a pre_deploy.php script, execute it. 
	 *
	 * @param type $releasePath
	 * @param type $currentPath 
	 */
	public function preLinkSwitch($releasePath) {
		$this->executeOptionalPhpScript($releasePath .'/'. $this->apppath .'/scripts/pre_switch.php', dirname($releasePath));

		$this->log("Setting silverstripe-cache permissions");
		$this->execute("chgrp -R $this->apachegroup $releasePath/silverstripe-cache", true);
		$this->execute("find $releasePath/silverstripe-cache -type f -exec chmod 664 {} \;", true);
		$this->execute("find $releasePath/silverstripe-cache -type d -exec chmod 2775 {} \;", true);
	}
	
	/**
	 * If the project has a post_deploy.php script, execute it. 
	 *
	 * @param type $releasePath
	 * @param type $currentPath 
	 */
	public function postDeploy($releasePath) {
		$arg = dirname($releasePath);
		$this->executeOptionalPhpScript($releasePath .'/'. $this->apppath .'/scripts/post_deploy.php', $arg);

		$this->log("Fixing permissions");
		// force silverstripe-cache permissions first before the rest. 
		$this->execute("chgrp -R $this->apachegroup $releasePath/silverstripe-cache", true);
		$this->execute("find $releasePath/silverstripe-cache -type f -exec chmod 664 {} \;", true);
		$this->execute("find $releasePath/silverstripe-cache -type d -exec chmod 2775 {} \;", true);
		
		$this->execute("chgrp -R $this->apachegroup $releasePath", true);
		$this->execute("find $releasePath -type f -exec chmod 664 {} \;", true);
		$this->execute("find $releasePath -type d -exec chmod 2775 {} \;", true);
		
		$this->executeOptionalPhpScript($releasePath .'/'. $this->apppath .'/scripts/finalise_deployment.php', dirname($releasePath));
	}
	
	protected function executeOptionalPhpScript($script) {
		$args = func_get_args();
		array_shift($args);
		
		$exe = escapeshellarg($script); 
		$args = array_map('escapeshellarg', $args);
		$args = implode(' ', $args);
		
		$cmd = "if [ -e $exe ]; then php $exe $args; fi";
		
		$this->log("Executing $script with args " . $args);
		$this->execute($cmd);
	}

	/**
	 * Executes a command over SSH
	 *
	 * @param string $cmd
	 * @return string
	 */
	protected function execute($cmd, $failOkay = false) {
		$command = '(' . $cmd . '  2>&1) && echo __COMPLETE';
		// $command = 'sh -c '.escapeshellarg('('.$this->command.'  2>&1) && echo __COMPLETE');

		$stream = ssh2_exec($this->connection, $command);
		if (!$stream) {
			throw new BuildException("Could not execute command!");
		}

		stream_set_blocking($stream, true);
		$data = '';
		while ($buf = fread($stream, 4096)) {
			$data .= $buf;
		}

		fclose($stream);

		if (strpos($data, '__COMPLETE') !== false || $this->ignoreerrors || $failOkay) {
			$data = str_replace('__COMPLETE', '', $data);
		} else {
			$this->log("Command failed: $command", Project::MSG_WARN);
			throw new BuildException("Failed executing command : $data");
		}


		return $data;
	}

	/**
	 * Copies a file to the remote system
	 *
	 * @param string $local
	 * @param string $remote 
	 */
	protected function copyFile($localEndpoint, $remoteEndpoint) {
		$port = $this->port ? " -P $this->port " : '';
		$this->exec("scp -i $this->privkeyfile $port $localEndpoint $this->username@$this->host:$remoteEndpoint");
		return;
		
		ssh2_sftp_mkdir($this->sftp, dirname($remoteEndpoint), 2775, true);
		$ret = ssh2_scp_send($this->connection, $localEndpoint, $remoteEndpoint);

		if ($ret === false) {
			throw new BuildException("Could not create remote file '" . $remoteEndpoint . "'");
		}
	}

	/**
	 * Connects SSH stuff up
	 */
	protected function connect() {
		if (!function_exists('ssh2_connect')) {
			throw new BuildException("To use SshTask, you need to install the SSH extension.");
		}

		$this->connection = ssh2_connect($this->host, $this->port);
		if (is_null($this->connection)) {
			throw new BuildException("Could not establish connection to " . $this->host . ":" . $this->port . "!");
		}

		$could_auth = null;
		if (strlen($this->pubkeyfile)) {
			$could_auth = ssh2_auth_pubkey_file($this->connection, $this->username, $this->pubkeyfile, $this->privkeyfile, $this->privkeyfilepassphrase);
		} else {
			$could_auth = ssh2_auth_password($this->connection, $this->username, $this->password);
		}

		if (!$could_auth) {
			throw new BuildException("Could not authenticate connection!");
		}

		$this->sftp = ssh2_sftp($this->connection);
	}

	public function setSapphirepath($path) {
		$this->sapphirepath = $path;
	}

	public function setApachegroup($g) {
		$this->apachegroup = $g;
	}

	public function getApachegroup() {
		return $this->apachegroup;
	}

	public function setLocalpath($p) {
		$this->localpath = $p;
	}

	public function getLocalpath() {
		return $this->localpath;
	}

	public function setApppath($p) {
		$this->apppath = $p;
	}

	public function getApppath() {
		return $this->apppath;
	}

	public function setRemotepath($p) {
		$this->remotepath = $p;
	}

	public function getRemotepath() {
		return $this->remotepath;
	}

	public function setPackage($pkg) {
		$this->package = $pkg;
	}

	public function getPackage() {
		return $this->package;
	}

	public function setIncremental($v) {
		if (!is_bool($v)) {
			$v = $v == 'true' || $v == 1;
		}

		$this->incremental = $v;
	}

	public function getIncremental() {
		return $this->incremental;
	}

	public function setInplace($v) {
		if (!is_bool($v)) {
			$v = $v == 'true' || $v == 1;
		}

		$this->inplace = $v;
	}

	public function getInplace() {
		return $this->inplace;
	}

	public function setHost($host) {
		$this->host = $host;
	}

	public function getHost() {
		return $this->host;
	}

	public function setPort($port) {
		if (strpos($port, '${') === false) {
			$this->port = $port;
		}
	}

	public function getPort() {
		return $this->port;
	}

	public function setUsername($username) {
		$this->username = $username;
	}

	public function getUsername() {
		return $this->username;
	}

	public function setPassword($password) {
		if (strpos($password, '${') === false) {
			$this->password = $password;
		}
	}

	public function getPassword() {
		return $this->password;
	}

	/**
	 * Sets the public key file of the user to scp
	 */
	public function setPubkeyfile($pubkeyfile) {
		if (strpos($pubkeyfile, '${') === false) {
			$this->pubkeyfile = $pubkeyfile;
		}
	}

	/**
	 * Returns the pubkeyfile
	 */
	public function getPubkeyfile() {
		return $this->pubkeyfile;
	}

	/**
	 * Sets the private key file of the user to scp
	 */
	public function setPrivkeyfile($privkeyfile) {
		$this->privkeyfile = $privkeyfile;
	}

	/**
	 * Returns the private keyfile
	 */
	public function getPrivkeyfile() {
		return $this->privkeyfile;
	}

	/**
	 * Sets the private key file passphrase of the user to scp
	 */
	public function setPrivkeyfilepassphrase($privkeyfilepassphrase) {
		$this->privkeyfilepassphrase = $privkeyfilepassphrase;
	}

	/**
	 * Returns the private keyfile passphrase
	 */
	public function getPrivkeyfilepassphrase($privkeyfilepassphrase) {
		return $this->privkeyfilepassphrase;
	}

	public function setCommand($command) {
		$this->command = $command;
	}

	public function getCommand() {
		return $this->command;
	}

	public function setIgnoreErrors($ignore) {
		if (!is_bool($ignore)) {
			$ignore = $ignore == 'true' || $ignore == 1;
		}

		$this->ignoreerrors = $ignore;
	}

	public function getIgnoreErrors() {
		return $this->ignoreerrors;
	}

}
