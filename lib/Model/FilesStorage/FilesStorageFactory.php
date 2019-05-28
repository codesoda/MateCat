<?php

namespace FilesStorage;

class FilesStorageFactory
{
    /**
     * @return AbstractFilesStorage
     */
    public static function create()
    {
        $storageMetohd = ( \INIT::$FILE_STORAGE_METHOD ) ? \INIT::$FILE_STORAGE_METHOD : 's3';

        if($storageMetohd === 'fs'){
            return new FsFilesStorage();
        }

        return new S3FilesStorage();
    }
}