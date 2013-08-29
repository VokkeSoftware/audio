<?php

namespace Jasny\Audio;

/**
 * Process a audio file using SoX.
 * 
 * @see http://sox.sourceforge.net/
 */
class Track
{
    /**
     * Path to sox binary
     * @var string
     */
    public static $sox;
    
    /**
     * Path to soxi binary
     * @var string
     */
    public static $soxi;
    
    /** @var string */
    public $filename;
    
    
    /** @var object */
    protected $stats;
    
    /** @var int */
    protected $sample_rate;
    
    /** @var int */
    protected $channels;
    
    /** @var int */
    protected $samples;
    
    /** @var int */
    protected $length;
    
    /** @var string */
    protected $annotations;
    
    
    
    /**
     * Class constructor
     * 
     * @param type $filename
     */
    public function __construct($filename)
    {
        $this->filename = $filename;
    }
    
    /**
     * Cast the track to a string
     * 
     * @return type
     */
    public function __toString()
    {
        return $this->filename;
    }

    
    /**
     * Get statistics of the audio file
     * 
     * @return array
     */
    public function getStats()
    {
        if (isset($this->stats)) return $this->stats;
        
        $stats = array();
        $stats['channels'] = '1';
        
        foreach (explode("\n", $this->sox('-n', 'stats')) as $line) {
            if (empty($line) || preg_match('/^\S+ WARN/', $line)) continue;
            
            if ($line[0] == ' ') {
                $stats['channels'] = (string)count(preg_split('/\s+/', trim($line))) - 1;
                continue;
            }
            
            list($key, $value) = preg_split('/\s{2,}/', $line) + array(1=>null);
            if (!isset($value)) continue;
            
            $key = strtolower(preg_replace(array('/\s(s|dB)$/', '/\W+/'), array('', '_'), $key));
            $value = preg_replace('/\s.*$/', '', $value);
            $stats[$key] = $value;
        }
        
        foreach (explode("\n", $this->sox('-n', 'stat')) as $line) {
            if (preg_match('/^\S+ WARN/', $line)) continue;
            
            list($key, $value) = explode(':', $line) + array(1=>null);
            if (!isset($value)) continue;
            
            if ($key == 'Samples read') $key = 'samples';
             elseif ($key == 'Length (seconds)') $key = 'length';
             else $key = strtolower(preg_replace('/\s+/', '_', $key));
            
            $stats[$key] = trim($value);
        }
        
        unset($stats['num_samples']);
        $stats['sample_rate'] = (string)round(($stats['samples'] / $stats['channels']) / $stats['length']);
        
        $this->stats = (object)$stats;
        return $this->stats;
    }
    
    /**
     * Get the a stat of the track
     * 
     * @param string $stat
     * @param string $soxi_arg
     * @param string $cast
     * @return mixed
     */
    private function getStat($stat, $soxi_arg, $cast)
    {
        if (!isset($this->$stat)) {
            $this->$stat = isset($this->stats) ?
                    (float)$this->stats->$stat :
                    (float)$this->soxi($soxi_arg);
        
            settype($this->$stat, $cast);
        }
        
        return $this->$stat;
    }
    
    /**
     * Get the sample rate of the track
     * 
     * @return int
     */
    public function getSampleRate()
    {
        return $this->getStat('sample_rate', '-r', 'int');
    }
    
    /**
     * Get the number of channels
     * 
     * @return int
     */
    public function getChannels()
    {
        return $this->getStat('channels', '-c', 'int');
    }
    
    /**
     * Get the number of samples
     * 
     * @return int
     */
    public function getSamples()
    {
        return $this->getStat('samples', '-s', 'int');
    }
    
    /**
     * Get the duration of the track in seconds
     * 
     * @return float
     */
    public function getLength()
    {
        return $this->getStat('length', '-D', 'float');
    }
    
    /**
     * Get the file comments (annotations)
     * 
     * @param boolean $parse   Parse and return a value object
     * @return string|object
     */
    public function getAnnotations($parse=false)
    {
        if (!isset($this->annotations)) $this->annotations = trim($this->soxi('-a'));
        
        if (!$parse) return $this->annotations;
        
        if (empty($this->annotations)) return (object)array();

        $result = array();
        foreach (explode("\n", $this->annotations) as $line) {
            if (empty($line)) continue;
            list($key, $value) = explode("=", $line, 2);
            $result[strtolower($key)] = $value;
        }
        
        return (object)$result;
    }
    
    
    /**
     * Plot a waveform for this audio track
     * 
     * @param array $settings
     * @return Waveform
     */
    public function getWaveform(array $settings=array())
    {
        return new Waveform($this, $settings);
    }
    
    
    /**
     * Execute sox.
     * Each argument will be used in the command.
     * 
     * @return string
     */
    public function sox()
    {
        $args = func_get_args();
        array_unshift($args, $this->filename);
        
        return self::exec('sox', $args);
    }
    
    /**
     * Execute soxi.
     * Each argument will be used in the command.
     * 
     * @return string
     */
    public function soxi()
    {
        $args = func_get_args();
        $args[] = $this->filename;
        
        return self::exec('soxi', $args);
    }
    
    
    /**
     * Determine path to executable
     * 
     * @return string
     */
    public static function which($cmd)
    {
        $path =& self::$$cmd;
        
        if (!isset($path)) $path = trim(shell_exec('which ' . escapeshellarg($cmd)));
        if (empty($path) || !file_exists($path)) throw new \Exception("$cmd executable not foud");
        
        return $path;
    }
    
    /**
     * Execute a command
     * 
     * @param string $cmd
     * @param string $args
     * @return string
     */
    protected static function exec($cmd, $args)
    {
        $command = escapeshellcmd(self::which($cmd)) . ' ' . join(' ', array_map('escapeshellarg', $args));
        
        $descriptorspec = array(
           1 => array("pipe", "w"),  // stdout
           2 => array("pipe", "w")   // stderr
        );

        $handle = proc_open($command, $descriptorspec, $pipes);
        if (!$handle) throw new \Exception("Failed to run sox command");
        
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        
        $ret = proc_close($handle);
        if ($ret != 0) throw new \Exception("$cmd command failed. " . trim($err));

        return $out ?: $err;
    }
}
