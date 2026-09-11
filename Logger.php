<?php


namespace BX;


class Logger
{
    public $path;
    public function __construct($folder= 'logs',$personal=false)
    {
        $this->path=$_SERVER['DOCUMENT_ROOT'].'/logs/'.date('m').'/'.date('d').'/'.$folder;
        if ($personal)
        {
            $this->path=$_SERVER['DOCUMENT_ROOT'].'/'.$personal.'/logs/'.date('m').'/'.date('d').'/'.$folder;
        }
    }

    public function directoriesList()
    {
        return explode('/',$this->path);
    }

    public function checkDirectories($directories)
    {
        $path='';

        foreach ($directories as $directory) {
            if (!empty($directory))
            {
                $path.='/'.$directory;
                if (strpos($path,'logs')===false){
                    continue;
                }

                if (!is_dir($path))
                {

                    if (!mkdir($path))
                    {
                        echo "<pre>";
                        echo var_export('Не удалось создать папку '.$path);
                        echo "</pre>";
                        return false;
                    }
                }
            }
        }
        return true;
    }

    public function saveFile($data,$name='log.txt')
    {
        if ($this->checkDirectories($this->directoriesList()))
        {
            file_put_contents($this->path.'/'.$name,print_r($data,1).
                "\r\n".date('H:i:s')."\r\n\r\n====================\r\n",FILE_APPEND);
        }
    }

}