<?php

/**
 * Remote Storage Driver
 * 
 * This is a baseclass for construcing plug-in modules that allow to send data 
 * to a remote storage.
 * 
 * You need to extend all abstract functions:
 * connect, disconnect, upload and getConfigFieldset
 * 
 * There is a base logging infrastructure you should use.
 * 
 * See StorageMail for an example.
 * 
 * NOTE: If your storage supports listing and deleting files then better extend 
 * RemoteDirectoryDirver to allow for automatic removal of old backup files.
 * 
 * See StorageFtp for an example.
 *
 * @copyright 2015, Roman Seidl
 * Licensed under GNU/GPL v2, see LICENSE.TXT
 */
abstract class RemoteStorageDriver extends Wire implements Module {

    /**
     * Path to the modules directory 
     * Used by subclasses to include libraries
     * @var string  
     */
    protected $moduleDir;

    /**
     * Keeps a refernce to the calling backup job to allow for logging
     * @var BackupJob 
     */
    protected $backup;

    /**
     * Processwire module init - sets the moduleDir. 
     * Please call this by calling parent::init() when overriding the method
     */
    public function init() {
        $this->moduleDir = dirname(__FILE__) . "/";
    }

    /**
     * Connect to storage - should throw an exception (preferred) or return false on failure
     */
    abstract public function connect($config);

    /**
     * Disconnect from storage
     */
    abstract public function disconnect();

    /**
     * Connect to storage - should throw an exception (preferred) or return false on failure
     */
    abstract public function upload($filename, $mimeType = null);

    /**
     * Fieldset with config data for establisihing the connection
     * @return InputfieldFieldset
     */
    abstract public static function getConfigFieldset($config);

    /**
     * Write a lin to the log
     * @param string $message
     */
    protected function log($message) {
        $this->backup->log($message);
    }

    /**
     * Write an array to the log - similar to print_r
     * @param array $message
     */
    protected function log_r($message) {
        $this->backup->log_r($message);
    }

    /**
     * For the loggin to work we need to call this to add a hook back to the job
     * @param BackupJob $backup
     */
    public function setBackupJob($backup) {
        $this->backup = $backup;
    }

    /**
     * Processwire installer 
     * Adds the class to the available Sorage Drivers
     * If you override this be sure to call parent::___install()
     */
    public function ___install() {
        echo get_called_class();
        $this->modules->get("ProcessRemoteBackup")->addStorageClass(get_called_class());
    }

    /**
     * Processwire uninstaller 
     * Removes the class to the available Sorage Drivers
     * If you override this be sure to call parent::___uninstall()
     */
    public function ___uninstall() {
        $this->modules->get("ProcessRemoteBackup")->removeStorageClass(get_called_class());
    }

}


