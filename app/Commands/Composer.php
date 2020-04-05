<?php
namespace App\Commands;

use LaravelZero\Framework\Commands\Command;
use Illuminate\Support\Facades\File;
use LaravelZero\Framework\Components\Logo\FigletString as ZendLogo;
use \Symfony\Component\Console\Formatter\OutputFormatterStyle;
use \Nadar\PhpComposerReader\ComposerReader;
use \Nadar\PhpComposerReader\RequireSection;
use PHLAK\SemVer\Version2;
use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use Kodus\Cache\FileCache;

class Composer extends Command
{
    /**
     * The Composer package manifest to audit
     */
    protected $file;

    /**
     * The packages specified as requirements
     */
    protected $packages = [];

    protected $packages_versions = [];

    protected $coordinates = array("coordinates" => []);

    protected $cache;

    protected $cached_vulnerabilities = array();

    protected $vulnerabilities = [];

    protected $user_name;

    protected $password;

    protected $styles = [];
    /**
     * Return the user's home directory.
     */
    protected function get_home() {
        // Cannot use $_SERVER superglobal since that's empty during UnitUnishTestCase
        // getenv('HOME') isn't set on Windows and generates a Notice.
        $home = getenv('HOME');
        if (!empty($home)) {
        // home should never end with a trailing slash.
        $home = rtrim($home, '/');
        }
        elseif (!empty($_SERVER['HOMEDRIVE']) && !empty($_SERVER['HOMEPATH'])) {
        // home on windows
        $home = $_SERVER['HOMEDRIVE'] . $_SERVER['HOMEPATH'];
        // If HOMEPATH is a root directory the path can end with a slash. Make sure
        // that doesn't happen.
        $home = rtrim($home, '\\/');
        }
        return empty($home) ? NULL : $home;
    }

    const CACHE_DEFAULT_EXPIRATION = 86400;
    const CACHE_DIR_MODE = 0775;
    const CACHE_FILE_MODE = 0664;

    public function __construct()
    {
        parent::__construct();
        $this->styles = array
        (
            // 'red' => new OutputFormatterStyle('red', null, ['bold']) //white text on red background
        );
    }

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'composer {file} 
                            {--user= : (Optional) OSS Index user to authenticate with.} 
                            {--pass= : (Optional) Password for OSS Index user to authenticate with.}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Audit Composer dependencies. Enter the path to composer.json after the command.';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        foreach($this->styles as $key => $value)
        {
            $this->output->getFormatter()->setStyle($key, $value);
        }

        $this->show_logo();
        
        if (!File::exists($this->argument('file')))
        {
            $this->error("The file " . $this->argument('file') . " does not exist");
            return;
        }

        $cachePath = $this->get_home() . DIRECTORY_SEPARATOR . ".cache";
        File::isDirectory($cachePath) or File::makeDirectory($cachePath, 0777, true, true);
        $this->cache = new FileCache($cachePath, self::CACHE_DEFAULT_EXPIRATION, self::CACHE_DIR_MODE, self::CACHE_FILE_MODE);
        $this->info("Cache directory is $cachePath.");
        $this->cache->cleanExpired();
        
        $this->file = realpath($this->argument('file'));
        $reader = new ComposerReader($this->file);
        if (!$reader->canRead()) {
            $this->error("Could not read composer file " . $this->argument('file') ."." );
            return;
        }
        $this->get_packages($reader);
        $this->comment("Parsed " . \count($this->packages) . " packages from " . $this->file . ":");
        if (count($this->packages) == 0)
        {
            $this->warn("No packages found to audit.");
            return;
        }

        $this->lock_file = dirname($this->file). DIRECTORY_SEPARATOR . 'composer.lock';
        if (File::exists($this->lock_file))
        {
            $this->comment("Composer lock file found at ".$this->lock_file). '.';
            $this->get_lock_file_packages($this->lock_file);
        }
        else {
            $this->warn("Did not find composer lock file found at ".$this->lock_file). '.' . ' Transitive package dependencies will not be audited.';
        }            
        $this->comment(count($this->packages) . " total packages to audit:");
        $this->get_packages_versions();
        $this->get_coordinates();
        $this->get_vulns();
        if(count($this->vulnerabilities) == 0)
        {
            $this->error("Did not receieve any data from OSS Index API.");
            return;
        }
        else
        {
            $this->comment("");
            $this->comment("Audit results:");
            $this->info("==============");
            foreach($this->vulnerabilities as $ov)
            {
                $v = (array) $ov;
                if (!array_key_exists("coordinates", $v))
                {
                    continue;
                }
                $p = "PACKAGE: " . str_replace("pkg:composer/", "", $v['coordinates']);
                $d = array_key_exists("description", $v) ? "DESC: " . $v['description'] : "";
                $is_vulnerable = array_key_exists("vulnerabilities", $v) ? (count($v['vulnerabilities']) > 0 ? true: false) : false;
                $is_vulnerable_text = "VULN: " . ($is_vulnerable ? "Yes" : "No");
                if(!$is_vulnerable) {
                    $this->info($p . " " . $d . " " . $is_vulnerable_text);
                }
                else {
                    $this->error($p . " " . $d . " " . $is_vulnerable_text);
                    foreach($v["vulnerabilities"] as $vuln)
                    {
                        foreach($vuln as $key => $value)
                        {
                            $this->comment("  " . $key . ": ".$value);
                        }
                    }
                }                
            }
        }
    }

    protected function show_logo()
    {
        $l = new ZendLogo('Bach', []);
        echo $l;
    }

    protected function get_packages($reader)
    {
        $section = new RequireSection($reader);
        foreach($section as $package) {
            $this->packages[$package->name] = $package->constraint;
        }
    }

    protected function get_lock_file_packages($lock_file)
    {
        $orig_count = count($this->packages);
        $data = \json_decode(\file_get_contents($lock_file), true);
        $lfp = $data["packages"];
        foreach ($lfp as $p)  {
            $n = $p["name"];
            $v = $p["version"];
            if (!\array_key_exists($n, $this->packages))
            { 
                $this->packages[$n] = $v;

            }
            else if (array_key_exists($n, $this->packages) && $this->packages[$n] != $v)
            { 
                $this->packages[$n] = $v;
            }

            if (\array_key_exists("require", $p))
            {
                foreach ($p["require"] as $rn => $rv) {
                    if (!\array_key_exists($rn, $this->packages))
                    { 
                        $this->packages[$rn] = $rv;
                    }
                    else if (array_key_exists($rn, $this->packages) && $this->packages[$rn] != $rv)
                    { 
                        $this->packages[$rn] = $rv;
                    }
                }
            }

            if (\array_key_exists("require-dev", $p))
            {
                foreach ($p["require-dev"] as $rn => $rv) {
                    if (!\array_key_exists($rn, $this->packages))
                    { 
                        $this->packages[$rn] = $rv;
                    }
                    else if (array_key_exists($rn, $this->packages) && $this->packages[$rn] != $rv)
                    { 
                        $this->packages[$rn] = $rv;
                    }
                }
            }
        }
        $count = count($this->packages);
        if ($count > $orig_count)
        {
            $this->comment("Added ".($count - $orig_count). " packages from Composer lock file at ".$lock_file.".");
        }
    }

    protected function get_version($constraint)
    {
        $range_prefix_tokens = array('>', '<', '=', '^', '~');
        
        $length = strlen($constraint);
        $start = 0;
        $end = $length - 1;
        if (!in_array($constraint[$start], $range_prefix_tokens) && is_numeric($constraint[$start]) && $constraint[$end] != '*')
        {
            return new Version2($constraint);
        }
        elseif (in_array($constraint[$start], $range_prefix_tokens) && !in_array($constraint[$start + 1], $range_prefix_tokens) 
            && is_numeric($constraint[$start + 1]) && $constraint[$end] != '*')
        {
            $v = new Version2(substr($constraint, 1, $length - 1));
            switch($constraint[$start])
            {
                case '=':
                    return $v;
                case '<':
                    return $v->decrement();
                case '>':
                case '^':
                case '~':
                    return $v->incrementMinor();
                default:
                    $this->warn("Did not determine version operator for constraint ". $constraint . ".");
                    return $v;
            }
        }
        elseif (in_array($constraint[$start], $range_prefix_tokens) && in_array($constraint[$start + 1], $range_prefix_tokens) 
            && $constraint[end] != '*')
        {
            $v = new Version2($substr($constraint,2, $length - 2));
            switch($constraint[$start].$constraint[$start + 1])
            {
                case '>=':
                case '<=':
                    return $v;
                default:
                    $this->warn("Did not determine version operator for constraint ". $constraint . ".");
                    return $v;

            }
        }
        else if (!in_array($constraint[$start], $range_prefix_tokens) && is_numeric($constraint[$start]) && $constraint[$end] == '*')
        {
            return new Version2(str_replace('*', 0, $constraint));
        }
        else
        {
            $this->warn("Could not determine version operator for constraint ".$constraint.".");
            return $constraint;
        }
    }

    protected function get_packages_versions()
    {
        foreach($this->packages as $package=>$constraint) 
        {
            $c = $constraint;
            if (strpos($constraint, "||") !== false) {
                $c = trim(explode("||", $constraint)[0]);
            }
            else if (strpos($constraint, "|") !== false) {
                $c = trim(explode("|", $constraint)[0]);
            }
            $c = trim($c, "v");
            if ($c == '*')
            {
                $c = '0.1';
            }
            try {
                $v = $this->get_version($c);
                $this->info($package . ' ' .$constraint . ' (' .$v. ')');
                $this->packages_versions[$package] = $v;
            } catch (\Throwable $th) {
                $this->error("Error occurred determinig version for package ".$package . "(".$constraint.")". ": ".$th->getMessage().".");
            }
        }        
    }

    protected function get_coordinates()
    {
        foreach($this->packages_versions as $package=>$version) 
        {
            $key = \base64_encode("pkg:composer/" . $package . "@".$version);
            if ($this->cache->has($key))
            {
                $this->cached_vulnerabilities += array("pkg:composer/" . $package . "@".$version => json_decode($this->cache->get($key)));
            }
            else 
            {
                array_push($this->coordinates["coordinates"], "pkg:composer/" . $package . "@".$version);
            }
        }
    }

    protected function get_vulns()
    {
        $c = count($this->cached_vulnerabilities);
        $qc = count($this->coordinates["coordinates"]);
        $this->vulnerabilities = $this->cached_vulnerabilities;
        $this->info("Vulnerability data for $c packages is cached.");
        if (count($this->coordinates["coordinates"]) == 0)
        {
            return;
        }
        $this->info("Querying OSS Index for $qc vulnerabilities...");
        $auth = array();
        if ($this->option('user') != "" && $this->option('pass') != "")
        {
            $u = $this->option('user');
            $p = $this->option('pass');
            $this->info("Using authentication for user $u.");
            $auth = [$this->option('user'), $this->option('pass')];
        }
        $client = new Client([
            // Base URI is used with relative requests
            'base_uri' => 'https://ossindex.sonatype.org/api/',
            // You can set any number of default request options.
            'timeout'  => 100.0,
            'auth' => $auth
        ]);
        
        try
        {
            $response = $client->post('v3/component-report', [
                RequestOptions::JSON => $this->coordinates
            ]);
            $code = $response->getStatusCode();
            if ($code != 200)
            {
                $this->error("HTTP request did not return 200 OK: ".$code . ".");
                return;
    
            }
            else
            {
                $r = json_decode($response->getBody(), true);
                foreach ($r as $v) {
                    $this->cache->set(\base64_encode($v['coordinates']), \json_encode($v));
                }
                $this->vulnerabilities += $r;
                return;
            }    
        }
        catch (Exception $e)
        {
            $this->error("Exception thrown making HTTP request: ".$e->getMessage() . ".");
            return;
        }
    }
}
