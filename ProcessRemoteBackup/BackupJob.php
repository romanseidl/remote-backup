<?php

/**
 * Runs a backup process and logs the results to stdout, file, mail and a buffer.
 *  
 * @copyright 2015, Roman Seidl
 * Licensed under GNU/GPL v2, see LICENSE.TXT
 */
class BackupJob {

    /**
     * Constants to set the zip filename
     */
    const timestampFormat = 'Ymd-His';
    const suffix = '.zip';

    /**
     * Job id as in ProcessRemoteBackup
     * @var int
     */
    private $id;

    /**
     * Job data as set by ProcessRemoteBackup
     * @var array job data associative array
     */
    private $data;

    /**
     * Directory where temporary files will be created
     * @var string path
     */
    private $backupdir;

    /**
     * Log buffer - allows to read out log 
     * @var string with newlines 
     */
    private $logBuffer;

    /**
     * Constructor
     * @param int $id job id - needed for logging and filenames
     * @param array $data job data associative array
     * @param bool $output should we output to the console?
     * @param bool $log should we log to a file?
     */
    public function BackupJob($id, $data, $backupdir, $output = true, $log = true) {
        if ($log)
            $this->fileLog = new FileLog($this->config->paths->logs . 'backup-.' . $id . 'txt');
        $this->outputEnabled = $output;

        $this->id = $id;
        $this->data = $data;
        $this->backupdir = $backupdir . $id . '/';
    }

    /**
     * test the connection
     * @throws Exception
     */
    public function testConnection() {
        $data = $this->data;
        $modules = wire('modules');
        $store = $modules->get($data["storageClass"]);
        $store->setBackupJob($this);

        try {
            if (!$store->connect($data["storageData"]))
                throw new Exception("Could not connect!");
            $this->log("Connected.");
        } catch (Exception $e) {
            
        }
        //finally workaround
        try {
            $this->log("Disconnecting.");
            $store->disconnect();
        } catch (Exception $ex) {
            
        }

        if ($e) {
            throw $e;
        }
        return true;
    }

    /**
     * Calculates if the job should not be repated due to limit in hours
     * @param int $runDate unix dat ein seconds
     * @param int $limit hours
     * @return bool
     */
    private function doNotRepeat($runDate, $limit) {
        return (time() - $runDate) < ($limit * 60 * 60);
    }

    /**
     * The main backup function - runs a backup for the respectve job 
     * @param bool $web is this called from the web?
     * @return array job run data to be saved
     */
    public function run($web = false) {

        //Change to site root - this is a sefety meassure for command line calls
        $config = wire('config');
        chdir($config->paths->root);

        try {
            $processData = $this->data[ProcessRemoteBackup::processData];
            $runDate = $date = time();

            if ($web) {
                $this->outputSetup();
                //Set abort and timout from config
                ignore_user_abort($processData['ignoreUserAbort']);
                set_time_limit($processData['timeLimit']);

                $this->log("Started from web interface by " . $_SERVER['REMOTE_ADDR']);
            } else {
                $this->log("Started from command line by " . get_current_user());
            }

            $this->log("Starting backup #" . $this->id);

            //check if this there was a recent sucessful job  
            $repeatLimitHours = $processData['repeatLimitHours'];
            $lastRunData = end($this->data[ProcessRemoteBackup::runData]);
            if (!$lastRunData['error'] && $this->doNotRepeat($lastRunData['runDate'], $repeatLimitHours)) {
                $this->log("There was a sucssful job within the set repeat limit of " . $repeatLimitHours . " hours.");
                $this->log("Aborting Backup.");
                $zip = $lastRunData['file'];
                $runDate = $lastRunData['runDate'];
            } else {
                $zip = $this->zip();
                $this->log_r($zip);
                $this->upload($zip);

                $this->log("Backup complete.");

                $this->mailLog();
            }
        } catch (Exception $e) {
            $this->log('Exception: ' . $e->getMessage());
            $error = $e->getMessage();
            $this->mailLog(true);
        }

        $this->data['runs'][] = array(
            'date' => $date,
            'runDate' => $runDate,
            'file' => basename($zip),
            'error' => $error,
            'log' => $this->logBuffer);
        return($this->data);
    }

    private function getPrefix() {
        return "remote-backup-" . $this->id . "-";
    }

    /**
     * Creates a zip file of the database and the site 
     * @return string zip filename
     */
    private function zip() {
        $data = $this->data;
        $backupdir = $this->backupdir;
        $prefix = $this->getPrefix();

        $repeatLimitHours = $data[ProcessRemoteBackup::processData]['repeatLimitHours'];
        //check if file exists?
        $file = glob($backupdir . $prefix . '*' . self::suffix);
        if (count($file) == 0 || !$this->doNotRepeat(filemtime($file[0]), $repeatLimitHours)) {
            //does the directory exist?
            if (!is_dir($backupdir)) {
                $this->mkdir($backupdir);
            }

            //delete old files
            $files = glob($backupdir . $prefix . '*' . self::suffix);
            foreach ($files as $file) { // iterate files
                if (is_file($file))
                    unlink($file); // delete file
            }

            //backup database
            $dbfile = $this->databaseDump();
            $zip = $this->zipFiles($dbfile);
            unlink($dbfile);
        } else {
            $this->log("Error: Will not create new ZIP. File exists and is newer than the set rpeat limit of " . $repeatLimitHours . " hours.");
            $zip = $file[0];
            $this - log("Will try to upload " . $file);
        }
        return $zip;
    }

    /**
     * Create a database dump file in the backup dir
     * @return string database backup filename
     */
    private function databaseDump() {
        $this->log("Exporting database...");

        $config = wire('config');

        $backup = new WireDatabaseBackup($this->backupdir);

        $backup->setDatabase($config->database);

        $backup->setDatabaseConfig($config);
        $backup->setPath($this->backupdir);
        $options = array();

        //change that to be safe
        $options['filename'] = 'install-full.sql';

        $result = $backup->backup($options);
        $this->log("Database export complete.");
        return $result;
    }

    /**
     * Zips the site files and includes the database file
     * @param string $dbfile file to include
     * @return string filename of the zipfile
     * @throws Exception 
     */
    private function zipFiles($dbfile) {
        $data = $this->data[ProcessRemoteBackup::processData];
        $zipfile = $this->backupdir . $this->getPrefix() . date(self::timestampFormat) . self::suffix;

        $this->log("Compressing files to " . $zipfile);
        $tmpzipfile = $zipfile . ".new";

        $backuppath = explode("\n", $data['backuppath']);

        if ($dbfile)
            $backuppath = array_merge(array(
                $dbfile
                    ), $backuppath);

        $config = wire('config');
        $excludepath = array_merge(array(
            $this->stripPath($config, $this->backupdir)
                ), explode("\n", $data['excludepath']));

        $this->log("Including files:");
        foreach ($backuppath as $path)
            $this->log("+ " . $path);

        $this->log("Excluding files:");
        foreach ($excludepath as $path)
            $this->log("- " . $path);

        $options = array(
            'allowHidden' => true,
            'exclude' => $excludepath
        );

        $result = wireZipFile($tmpzipfile, $backuppath, $options);

        $errors = array_merge($errors, $result['errors']);
        $cnt = count($result['files']);
        $this->log(sprintf('Added %d template and module files to ZIP', $cnt));

        if (!$errors)
            rename($tmpzipfile, $zipfile);
        else {
            foreach ($errors as $error)
                $this->log("ERROR: " . $error);
            unlink($tmpzipfile);
            throw new Exception($errors);
        }
        return $errors ? false : $zipfile;
    }

    //@todo Rework this
    private static function stripPath($config, $excludePath) {
        return str_replace(realpath($config->paths->root) . "/", "", realpath($excludePath));
    }

    /**
     * Connects to service and uploads a file and unlinks it on success
     * @param string $file path of the file to upload
     * @throws Exception
     */
    private function upload($file) {
        $data = $this->data;
        $id = $this->id;

        $this->log("Uploading: " . $file);
        if (is_file($file)) {

//            set_time_limit(0);
//            ignore_user_abort(true);
            $modules = wire('modules');
            $store = $modules->get($data["storageClass"]);
            $store->setBackupJob($this);

            try {
                $this->log("Connecting to: " . $store);
                if (!$store->connect($data["storageData"]))
                    throw new Exception("Could not connect!");

                $this->log("Connected");

                //Auto delete functions
                if ($data['keepDays'])
                    $store->keepNewer($this->getPrefix($id), time() - ($data['keepDays'] * 24 * 60 * 60));
                if ($data['keepNumber'])
                    $store->keepNumber($this->getPrefix($id), $data['keepNumber'] - 1);
                if ($data['keepSize'])
                    $store->keepSize($this->getPrefix($id), ($data['keepSize'] * 1024 * 1024) - filesize($file));


                $this->log("Uploading: " . $file);
                if ($store->upload($file)) {
                    $this->log("Upload complete. Deleting file: " . $file);
                    unlink($file);
                    $this->log("File deleted.");
                } else
                    throw new Exception("Upload failed!");
            } catch (Exception $e) {
                
            }
            //finally workaround
            try {
                $this->log("Disconnecting.");
                $store->disconnect();
            } catch (Exception $ex) {
                
            }
            if ($e)
                throw $e;
        } else {
            throw new Exception("Nothing to upload!");
        }
    }

    /**
     * Output setup for logging directly to a web client
     */
    private function outputSetup() {
        header('Cache-Control: no-cache');
        if ($this->outputEnabled) {
            header('Content-Type: text/plain');
            if (function_exists('apache_setenv')) {
                @apache_setenv('no-gzip', '1');
            }
            @ini_set('zlib.output_compression', 'Off');
            @ini_set('output_buffering ', '0');
            @ini_set('implicit_flush', '1');
            @ob_implicit_flush(true);
            @ob_end_flush();
        }
    }

    //---Logging-----------------------------------------------

    /**
     * Writes a message to the logs (stdout, file, buffer)
     * @param string $message
     */
    public function log($message) {
        if ($this->outputEnabled) {
            echo @date('Y-m-d H:i:s ') . $message . "\n";
            @ob_flush();
        }

        if ($this->fileLog)
            $this->fileLog->save($message);

        $this->logBuffer .= @date('Y-m-d H:i:s ') . $message . "\n";
    }

    /**
     * Writes to logs - similar to print_r
     * @param array $messages
     */
    public function log_r($messages) {
        $this->log(print_r($messages, true));
    }

    /**
     * Clears the log buffer
     */
    private function clearLog() {
        $this->logBuffer = "";
    }

    /**
     * returns the logBuffer
     * @return string with newlines
     */
    public function getLogBuffer() {
        return $this->logBuffer;
    }

    /**
     * returns the logBuffer
     * @return string with newlines
     */
    public function getLogBufferHTML() {
        return str_replace("\n", "<br/>", $this->logBuffer);
    }

    /**
     * Mails the log buffer to the set reciepient if configured in the $config
     * @param bool $error is this an error log?
     */
    private function mailLog($error = false) {
        $data = $this->data[ProcessRemoteBackup::processData];
        if ($data['mailTo'] && ( (!$error && $data['mailOnSuccess']) || ($error && $data['mailOnFailure']) )) {
            $mail = wireMail();

            $subject = $error ? $data['errorMailSubject'] : $data['mailSubject'];
            $sender = $data['mailTo'];
            $mail->to($sender);
            $mail->from($sender);
            $mail->subject($subject);
            $mail->body($this->logBuffer);
            $mail->send();

            $this->log("Log mailed to " . $sender);
        }
    }

    /**
     * Returns a form for the config of the job
     * @param array $data data to fill in the form
     * @param bool $directory if the remote storage is of class RemoteDirectoryDriver the there are delete options
     * @return InputfieldFieldset fieldset
     */
    public static function getFieldset($data, $directory = false) {
        $config = wire('config');
        $defaults = array(
            'backuppath' => implode("\r\n", array(
                "site",
                "index.php",
                ".htaccess"
            )),
            'excludepath' => implode("\r\n", array(
                self::stripPath($config, $config->paths->cache),
                self::stripPath($config, $config->paths->sessions),
            )),
            'deleteOldFiles' => true,
            'deleteFilesAfterDays' => 7,
            'mailSubject' => "Automatic Backup Successful",
            'errorMailSubject' => "Automatic Backup Failed",
            'mailTo' => $config->adminEmail ? $config->adminEmail : $user->email,
            'mailOnFailure' => true,
            'mailOnSuccess' => false,
            'timeLimit' => 600,
            'ignoreUserAbort' => true,
            'repeatLimitHours' => 12
        );
        $data = $data ? array_merge($defaults, $data) : $defaults;

        $modules = wire('modules');

        $wrapper = $modules->get('InputfieldFieldset');

        $left = $modules->get('InputfieldFieldset');
        $left->columnWidth = 50;

        //Files
        $fieldset = $modules->get('InputfieldFieldset');
        $fieldset->columnWidth = 100;
        $fieldset->label = __("Files to Backup");

        $f = $modules->get('InputfieldTextarea');
        $f->name = "backuppath";
        $f->label = __("Include Files / Directories");
        $f->description = __("Files/Directories to include relative to the root of the Processwire site. In case you just want a database backup leave this empty.");
        $f->value = $data["backuppath"];
        $fieldset->append($f);

        $f = $modules->get('InputfieldTextarea');
        $f->name = "excludepath";
        $f->label = __("Exclude Files / Directories");
        $f->description = __("Files/Directories to exclude relative to the root of the Processwire site.");
        $f->value = $data["excludepath"];
        $fieldset->append($f);

        $left->append($fieldset);

        //Directory functions
        if ($directory) {
            $fieldset = $modules->get('InputfieldFieldset');
            $fieldset->columnWidth = 100;
            $fieldset->label = __("Auto Delete");


            $f = $modules->get('InputfieldInteger');
            $f->name = "keepDays";
            $f->label = __("Keep backup files for # days");
            $f->description = __("Files will kept on the server for the given number of days. Empty: Keep any age.");
            $f->value = $data["keepDays"];
            $fieldset->append($f);


            $f = $modules->get('InputfieldInteger');
            $f->name = "keepNumber";
            $f->label = __("Keep # of backup files");
            $f->description = __("The given number of files will be kept on the server. Empty: Keep any number.");
            $f->value = $data["keepNumber"];
            $fieldset->append($f);

            $f = $modules->get('InputfieldInteger');
            $f->name = "keepSize";
            $f->label = __("Keep MB of backup data");
            $f->description = __("The given amount of data will be kept on the server. Empty: Keep any amount.");
            $f->value = $data["keepSize"];
            $fieldset->append($f);

            $left->append($fieldset);
        }

        //Web Job
        $fieldset = $modules->get('InputfieldFieldset');
        $fieldset->columnWidth = 100;
        $fieldset->label = __("Web Job Settings");
        $f->description = __("These values only get set if the job is called via the web interface");

        $f = $modules->get('InputfieldInteger');
        $f->name = "timeLimit";
        $f->label = __("Timeout");
        $f->description = __("Set the timeout to a number of seconds (php: set_time_limit() - if the web sevrer allows ot set the value). Empty: do not set");
        $f->value = $data["timeLimit"];
        $fieldset->append($f);

        $f = $modules->get('InputfieldCheckbox');
        $f->name = "ignoreUserAbort";
        $f->label = __("Ignore User Abort");
        $f->description = __("Should make the job continue when the http connection gets lost (php: ignore_user_abort()).");
        $f->checked = $data["ignoreUserAbort"];
        $fieldset->append($f);

        $left->append($fieldset);

        $wrapper->append($left);

        $right = $modules->get('InputfieldFieldset');
        $right->columnWidth = 50;

        //Mail
        $fieldset = $modules->get('InputfieldFieldset');
        $fieldset->columnWidth = 100;
        $fieldset->label = __("Mail Settings");

        $f = $modules->get('InputfieldText');
        $f->name = "mailSubject";
        $f->label = __("Success Mail Subject");
        $f->description = __("Subject of the mail sent when sending sucess log");
        $f->value = $data["mailSubject"];
        $fieldset->append($f);

        $f = $modules->get('InputfieldText');
        $f->name = "errorMailSubject";
        $f->label = __("Error Mail Subject");
        $f->description = __("Subject of the error mail sent when sending log on error");
        $f->value = $data["errorMailSubject"];
        $fieldset->append($f);

        $f = $modules->get('InputfieldText');
        $f->name = "mailTo";
        $f->label = __("Mail to");
        $f->description = __("Email address to send the log to. If left blank no logs are mailed");
        $f->value = $data["mailTo"];
        $fieldset->append($f);

        $f = $modules->get('InputfieldCheckbox');
        $f->name = "mailOnFailure";
        $f->label = __("Mail on failure");
        $f->description = __("Send logs on failure?");
        $f->checked = $data["mailOnFailure"];
        $fieldset->append($f);

        $f = $modules->get('InputfieldCheckbox');
        $f->name = "mailOnSuccess";
        $f->label = __("Mail on success");
        $f->description = __("Send logs also on success?");
        $f->checked = $data["mailOnSuccess"];
        $fieldset->append($f);

        $right->append($fieldset);

        //Mail
        $fieldset = $modules->get('InputfieldFieldset');
        $fieldset->columnWidth = 100;
        $fieldset->label = __("Repeat Setting");

        $f = $modules->get('InputfieldInteger');
        $f->name = "repeatLimitHours";
        $f->label = __("Do not Repeat for #hours");
        $f->description = __("After success the job will not be repeated for the set amount of hours when called. This is useful for web cron which might be unreliable. You can just hit the job multiple times with a resaonable timespan in between. Empty: No Limit");
        $f->value = $data["repeatLimitHours"];
        $fieldset->append($f);

        $right->append($fieldset);

        $wrapper->append($right);
        return $wrapper;
    }

    public function mkdir($backupdir) {
        while (!is_dir($backupdir)) {
            $dir = $backupdir;
            while (!is_dir(dirname($dir)))
                $dir = dirname($dir);
            $this->log("Creating directory " . $dir);
            wireMkdir($dir);
        }
    }

}
