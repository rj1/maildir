<?php
namespace Rj1\Maildir;

class Maildir
{
    protected static int $count = 0;
    protected static array $dirs = ['cur', 'new', 'tmp'];
    public  $basedir;

    function __construct($basedir) {
        if(!self::verify($basedir))
            throw new \LogicException('not a valid Maildir. try using ' . get_class() . '::create()');
    }

    /**
     * create Maildir and initialize class
     * @param string $basedir path to the directory to be used as a Maildir
     * @return object
     */
    static function create($basedir) : Maildir {
        if(!is_dir($basedir)) {
            throw new \RuntimeException($basedir . ' is not a directory');
        }

        foreach(self::$dirs as $dir) {
            mkdir($basedir . '/' . $dir);
        }

        return new self($basedir);
    }

    /**
     * delete maildir
     * @param string $basedir path to the directory to be destroyed
     */
    static function destroy($basedir) {
        if(!is_dir($basedir)) {
            throw new \RuntimeException($basedir . ' is not a directory');
        }

        foreach(self::$dirs as $dir) {
            $path = $basedir . '/' . $dir;
            if(is_dir($path)) {
                array_map('unlink', glob($path . '/*'));
                rmdir($path);
            }
        }
    }

    /**
     * verify maildir is structured correctly
     * @param string $dir path to the Maildir
     * @return bool
     */
    static function verify($dir) : bool {
        return (is_dir($dir . '/cur')
            && is_dir($dir . '/new')
            && is_dir($dir . '/tmp'));
    }

    /**
     * generate filename to be used for a mail
     * @return string filename
     */
    static function createName() : string {
        $time = gettimeofday();
        $left = $time['sec'];
        $M = 'M' . $time['usec'];
        $P = 'P' . getmypid();
        $Q = 'Q' . (++self::$count);
        $right = gethostname();
        return "$left.$M$P$Q.$right";
    }

    /**
     * check if this mail has 'new' tag
     * @param string $name name of mail
     * @return bool
     */
    function isNew(string $name) : bool {
        return is_file($this->basedir . '/new/' . $name);
    }

    /**
     * save an email
     * @param string $mailContent the contents of the email
     * @return string name of the mail
     */
    function saveMail($mailContent) : string {
        $name = $this->createName();
        $mv = file_put_contents($this->basedir . '/tmp/' . $name, $mailContent);
        if($mv == false)
            throw new \RuntimeException('failed to write to ' . $this->basedir);
        $mv = rename($this->basedir . '/tmp/' . $name,
            $this->basedir . '/new/' . $name);
        if($mv == false)
            throw new \RuntimeException('mv failed: ' . $name . ' -> new in ' . $this->basedir);
        return $name;
    }

    function exists(string $name) : bool {
        return $this->isNew($name) || ($this->findFilename($name) !== false);
    }

    function fetch(string $name) {
        $this->cur($name);

        $filename = $this->findFilename($name);
        if($filename === false) {
            throw new \RuntimeException("unable to find $name");
        }

        return file_get_contents($this->basedir . '/cur/' . $filename);
    }

    function cur(string $name) {
        if(is_file($this->basedir . '/new/' . $name)) {
            rename($this->basedir . '/new/' . $name,
                $this->basedir . '/cur/' . $name . ':2,');
        }
    }

    function getStream(string $name) {
        $this->cur($name);

        $filename = $this->findFilename($name);
        if($filename === false) {
            throw new \RuntimeException("unable to find $name");
        }

        return fopen($this->basedir . '/cur/' . $filename, "r");
    }

    function getStreams() {
        foreach($this->getNames() as $name) {
            yield $name => $this->getStream($name);
        }
    }

    function rm(string $name) {
        if(is_file($this->basedir . '/new/' . $name)) {
            unlink($this->basedir . '/new/' . $name);
        }
        else {
            $filename = $this->findFilename($name);
            if($filename === false)
                throw new \RuntimeException("unable to find $name");
            unlink($this->basedir . '/cur/' . $filename);
        }
    }

    protected function clrTmp() {
        array_map('unlink', glob($this->basedir . '/tmp/*'));
    }

    function getFlags(string $name) {
        $this->cur($name);
        $fn = $this->findFilename($name);
        if($fn === false) {
            return false;
        }

        $flags = substr($fn, strpos($fn, ':')+3);
        return $flags;
    }

    function hasFlag(string $name, string $flag) : bool {
        $flags = $this->getFlags($name);
        return strpos($flags, $flag) !== false;
    }

    function clrFlag(string $name, string $flag) {
        $flags = $this->getFlags($name);
        $newFlags = str_replace($flag, '', $flags);
        if($newFlags == $flags)
            return;
        rename($this->basedir . '/cur/' . $name . ':2,' . $flags,
            $this->basedir . '/cur/' . $name . ':2,' . $newFlags);
    }

    function setFlags(string $name, string $flag) {
        $flags = $this->getFlags($name);
        if(strpos($flags, $flag) !== false) {
            return;
        }

        $f = str_split($flags);
        $f[] = $flag;
        sort($f);
        $newFlags = implode('', $f);
        rename($this->basedir . '/cur/' . $name . ':2,' . $flags,
            $this->basedir . '/cur/' . $name . ':2,' . $newFlags);
    }

    function getNames() {
        array_map([$this, 'touch'], array_map('basename', glob($this->basedir . '/new/*')));

        foreach(array_map('basename', glob($this->basedir . '/cur/*')) as $filename) {
            yield $this->extractName($filename);
        }
    }

    function getFiles() {
        foreach($this->getNames() as $name) {
            yield $name => $this->fetch($name);
        }
    }

    function getPath(string $name) {
        $filename = $this->findFilename($name);
        if($filename) {
            return $this->basedir . '/cur/' . $filename;
        }
        if($this->isNew($name)) {
            return $this->basedir . '/new/' . $name;
        }
        return false;
    }

    protected function findFilename(string $name) {
        $handle = opendir($this->basedir . '/cur');
        $ret = false;
        while(false !== ($entry = readdir($handle))) {
            if(substr($entry, 0, strpos($entry, ':')) == $name) {
                $ret = $entry;
                break;
            }
        }

        closedir($handle);
        return $ret;
    }

    protected function extractName(string $filename) : string {
        return substr($filename, 0, strpos($filename, ':'));
    }
}

