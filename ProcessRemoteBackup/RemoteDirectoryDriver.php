<?php

/**
 * Remote Directory Driver
 * 
 * This is a baseclass for construcing plug-in modules that allow to send data 
 * to a remote storage and list and delete old files.
 * 
 * You need to extend all abstract functions:
 * connect, disconnect, upload, find, size, mdate, delete and getConfigFieldset
 * 
 * There is a base logging infrastructure you should use.
 * 
 * See StorageFtp for an example.
 * 
 * NOTE: If your storage does not support listing then extend 
 * RemoteStorageDriver.
 * 
 * @copyright 2015, Roman Seidl
 * Licensed under GNU/GPL v2, see LICENSE.TXT
 */
require_once("RemoteStorageDriver.php");

abstract class RemoteDirectoryDriver extends RemoteStorageDriver {

    /**
     * Find files starting with the given prefix
     * @param string $prefix file prefix
     * @return array fileIds - a flat array with file ids (e.g. name or number)
     */
    abstract protected function find($prefix);

    /**
     * Get file size in bytes
     * Will only be called for files that have allready been listed with find()
     * Thus caching when listing is OK (more efficient for services that return 
     * size along with ids).
     * @param int $id file id
     * @return int file size in bytes 
     */
    abstract protected function size($id);

    /**
     * Get modified date
     * Will only be called for files that have allready been listed with find()
     * Thus caching when listing is OK (more efficient for services that return 
     * modified date along with ids).
     * @param int $id file id
     * @return int modified date as unix date in seconds
     */
    abstract protected function mdate($id);

    /**
     * Delete file by id - should thow Exception if not successful
     * @param int $id file id 
     */
    abstract protected function delete($id);

    /**
     * Finds matching files and sorts them according to their modified date
     * @param string $prefix file prefix
     * @param bool $reverse will revers order (new files first)
     * @return associative array(mdate => id)
     */
    private function sortedList($prefix, $reverse = false) {
        $files = $this->find($prefix);
        $filelist = array();
        foreach ($files as $file)
            $filelist[$this->mdate($file)] = $file;
        if ($reverse)
            krsort($filelist);
        else
            ksort($filelist);
        return $filelist;
    }

    /**
     * Will remove files from server that are older than the given date
     * @param string $prefix file matching prefix
     * @param int $date unix date in seconds
     */
    public function keepNewer($prefix, $date) {
        $this->log("Deleting by date: Keeping files newer than " . date(DATE_RFC822, $date) . " (prefix: " . $prefix . ").");
        $files = $this->sortedList($prefix);
        foreach ($files as $fdate => $file)
            if ($fdate < $date) {
                $this->log("Deleting by date: Deleting " . $file . " (" . date(DATE_RFC822, $fdate) . ")");
                $this->delete($file);
            } else
                break;
    }

    /**
     * Will remove old files from server to keep the given number of files
     * @param string $prefix file matching prefix
     * @param int $number number of files to keep
     */
    public function keepNumber($prefix, $number) {
        $this->log("Deleting by number: Keeping " . $number . " files (prefix: " . $prefix . ").");
        $files = $this->sortedList($prefix);

        $nfiles = count($files);
        $delete = $nfiles - $number;

        $i = 0;
        if ($delete > 0) {
            $this->log("Deleting by number: Deleting the " . $delete . " oldest of " . $nfiles . " files.");
            foreach ($files as $fdate => $file)
                if (++$i <= $delete) {
                    $this->log("Deleting by number: Deleting " . $file);
                    $this->delete($file);
                } else
                    break;
        } else {
            $this->log("Deleting by number: Nothing to delete. " . $nfiles . " files on server.");
        }
    }

    /**
     * Remove old files to keep a maximum space of the given size
     * @param string $prefix file matching prefix
     * @param int $bytes number of bytes to keep
     */
    public function keepSize($prefix, $bytes) {
        $this->log("Deleting by size: Keeping " . sprintf("%.1f", $bytes / (1024 * 1024)) . "MB of (newest) backup data (prefix: " . $prefix . ").");

        $files = $this->sortedList($prefix, true);
        $size = 0;
        foreach ($files as $fdate => $file) {
            $size += $this->size($file);
            if ($size > $bytes) {
                $this->log("Deleting by size: Deleting " . $file);
                $this->delete($file);
            } else {
                $kept += 1;
                $keptSize = $size;
            }
        }
        $this->log("Deleting by size: Kept " . $kept . " files with " . sprintf("%.1f", $keptSize / (1024 * 1024)) . "MB of backup data.");
    }

}
