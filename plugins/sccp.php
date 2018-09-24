<?php declare(strict_types=1);
/*
Copyright (c) 2018 Tyson Andre

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
*/
// Known bugs:
// SCCP invocation sometimes doesn't return anything.
class InvokeExecutionPromiseSCCP
{
    /** @var string path to the php binary invoked */
    private $binary;

    /** @var bool */
    private $done = false;

    /** @var resource */
    private $process;

    /** @var array{0:resource,1:resource,2:resource} */
    private $pipes;

    /** @var ?string */
    private $error = null;

    /** @var ?string */
    private $output = null;

    /** @var string */
    private $raw_stderr = '';

    /** @var string */
    private $abs_path;

    public function __construct(string $binary, string $file_contents, string $abs_path, bool $optimize)
    {
        // TODO: Use symfony process
        // Note: We might have invalid utf-8, ensure that the streams are opened in binary mode.
        // I'm not sure if this is necessary.

        $optimization_level = $optimize ? '-1' : '0';
        $debug_level = $optimize ? '0x20000' : '0x10000';
        $cmd = $binary . " -d opcache.enable_cli=1 -d opcache.opt_debug_level=$debug_level -d opcache.optimization_level=$optimization_level --syntax-check $abs_path 1>&2";

        if (DIRECTORY_SEPARATOR === "\\") {

            if (!function_exists('opcache_get_status')) {
                $extension = 'opcache.dll';
                $cmd .= " -d zend_extension=$extension";
            }
            if (!file_exists($abs_path)) {
                $this->done = true;
                $this->error = "File does not exist";
                return;
            }

            // Possibly https://bugs.php.net/bug.php?id=51800
            // NOTE: Work around this by writing from the original file. This may not work as expected in LSP mode
            $abs_path = str_replace("/", "\\", $abs_path);

            $cmd .= ' < ' . escapeshellarg($abs_path);

            $descriptorspec = [
                2 => ['pipe', 'wb'],
            ];
            $this->binary = $binary;
            $process = proc_open($cmd, $descriptorspec, $pipes);
            if (!is_resource($process)) {
                $this->done = true;
                $this->error = "Failed to run proc_open in " . __METHOD__;
                return;
            }
            $this->process = $process;
        } else {
            if (!function_exists('opcache_get_status')) {
                $extension = 'opcache.so';
                $cmd .= " -d zend_extension=$extension";
            }
            //echo "Invoking $cmd\n";
            $descriptorspec = [
                2 => ['pipe', 'wb'],
            ];
            $this->binary = $binary;
            $process = proc_open($cmd, $descriptorspec, $pipes);
            if (!is_resource($process)) {
                $this->done = true;
                $this->error = "Failed to run proc_open in " . __METHOD__;
                return;
            }
            $this->process = $process;

            // self::streamPutContents($pipes[0], $file_contents);
        }
        $this->pipes = $pipes;

        if (!stream_set_blocking($pipes[2], false)) {
            $this->error = "unable to set read stderr to non-blocking";
        }
        $this->abs_path = $abs_path;
    }

    /**
     * @param resource $stream stream to write $file_contents to before fclose()
     * @param string $file_contents
     * @return void
     * See https://bugs.php.net/bug.php?id=39598
     */
    private static function streamPutContents($stream, string $file_contents)
    {
        try {
            while (strlen($file_contents) > 0) {
                $bytes_written = fwrite($stream, $file_contents);
                if ($bytes_written === false) {
                    error_log('failed to write in ' . __METHOD__);
                    return;
                }
                if ($bytes_written === 0) {
                    $read_streams = [];
                    $write_streams = [$stream];
                    $except_streams = [];
                    stream_select($read_streams, $write_streams, $except_streams, 0);
                    if (!$write_streams) {
                        usleep(1000);
                        // This is blocked?
                        continue;
                    }
                    // $stream is ready to be written to?
                    $bytes_written = fwrite($stream, $file_contents);
                    if (!$bytes_written) {
                        error_log('failed to write in ' . __METHOD__ . ' but the stream should be ready');
                        return;
                    }
                }
                if ($bytes_written > 0) {
                    $file_contents = \substr($file_contents, $bytes_written);
                }
            }
        } finally {
            fclose($stream);
        }
    }

    public function read() : bool
    {
        if ($this->done) {
            return true;
        }
        $stderr = $this->pipes[2];
        while (!feof($stderr)) {
            if (!feof($stderr)) {
                $bytes = fread($stderr, 4096);
                if (strlen($bytes) === 0) {
                    break;
                }
                $this->raw_stderr .= $bytes;
            }
        }
        if (!feof($stderr)) {
            return false;
        }
        fclose($stderr);

        $this->done = true;

        $exit_code = proc_close($this->process);
        if ($exit_code === 0) {
            $this->output = str_replace("\r", "", trim($this->raw_stderr));
            $this->error = null;
            return true;
        }
        $output = str_replace("\r", "", trim($this->raw_stderr));
        $first_line = explode("\n", $output)[0];
        $this->error = $first_line;
        return true;
    }

    /**
     * @return void
     * @throws Error if reading failed
     */
    public function blockingRead()
    {
        if ($this->done) {
            return;
        }
        if (!stream_set_blocking($this->pipes[2], true)) {
            throw new Error("Unable to make stderr blocking");
        }
        if (!$this->read()) {
            throw new Error("Failed to read");
        }
    }

    /**
     * @return ?string
     * @throws RangeException if this was called before the process finished
     */
    public function getError()
    {
        if (!$this->done) {
            throw new RangeException("Called " . __METHOD__ . " too early");
        }
        return $this->error;
    }

    /**
     * @return ?string
     * @throws RangeException if this was called before the process finished
     */
    public function getOutput()
    {
        if (!$this->done) {
            throw new RangeException("Called " . __METHOD__ . " too early");
        }
        return $this->output;
    }

    public function getAbsPath() : string
    {
        return $this->abs_path;
    }

    public function getBinary() : string
    {
        return $this->binary;
    }
}

class SCCPOpline
{
    /** @var string */
    public $opline;
    /** @var string */
    public $opcode;
    /** @var bool */
    public $valid = false;

    public function __construct(string $opline) {
        $parts = preg_split('/\s+/', $opline, 4);
        $this->opcode = $parts[2] ?? '';
        $this->opline = trim($this->opcode . ' ' . ($parts[3] ?? ''));
        $this->valid = $this->opcode !== '';
    }

    /**
     * This is a heuristic
     */
    public function isDynamic() : bool {
        // This excludes DO_FCALL, JMP, etc.
        return !preg_match('/^(RETURN\b|CV[0-9]+\(|[TV][0-9]+\s+=|VERIFY_RETURN_TYPE\b)/', $this->opline);
    }

    public function isSimpleReturn() : bool {
        return preg_match('/^(RETURN\b|CV[0-9]+\(|V[0-9]+\s+=|VERIFY_RETURN_TYPE\b)/', $this->opline) > 0;
    }
}

class SCCPFunction
{
    /** @var array<int,string> */
    public $lines;
    /** @var ?string */
    public $function_name;

    /** @var bool */
    public $valid = false;

    /** @var ?array{0:int,1:int} */
    public $range;

    /** @var bool */
    public $oplines = false;

    /** @param array<int,string> $lines */
    public function __construct(array $lines) {
        $remaining_lines = array_reverse($lines);
        if (preg_match('/^(\S+): ; \(lines=/', $lines[0], $matches)) {
            $this->function_name = $matches[1];
            //echo "Found a function name $this->function_name\n";
        } else {
            return;
        }
        array_pop($remaining_lines);
        while (count($remaining_lines) > 0) {
            $line = array_pop($remaining_lines);
            //echo "Checking $line\n";
            if (preg_match('/^\s+;\s+.*([0-9]+)-([0-9]+)$/', $line, $matches)) {
                //echo "Found a range\n";
                $this->range = [(int)$matches[1], (int)$matches[2]];
                break;
            }
        }
        if (!$this->range) {
            return;
        }
        $expected_opline = 0;
        while (count($remaining_lines) > 0) {
            $line = array_pop($remaining_lines);
            $pattern = '/^L' . $expected_opline . ' \([0-9]+\):/';
            if (preg_match($pattern, $line)) {
                $this->oplines[$expected_opline] = new SCCPOpline($line);
                $expected_opline++;
            }
        }
        // Even a void has at least one opcode?
        $this->valid = $expected_opline > 0;
    }

    // Implies isSimpleReturn() is false
    public function isDynamic() : bool {
        assert($this->valid === true);
        // There should be at least one opline
        foreach ($this->oplines as $opline) {
            if ($opline->isDynamic()) {
                return true;
            }
        }
        return false;
    }

    public function isSimpleReturn() : bool {
        assert($this->valid === true);
        foreach ($this->oplines as $opline) {
            if (!$opline->isSimpleReturn()) {
                return false;
            }
        }
        return true;
    }

    public function opcodeDump() : string
    {
        $result = '';
        foreach ($this->oplines as $opline) {
            $result .= $opline->opline . "\n";
        }
        return $result;
    }
}

class SCCPHeuristicParser
{
    /**
     * @return array<int,SCCPFunction>
     */
    private static function parseSections(string $raw) : array {
        $sections = [];
        $current_section = [];
        foreach (explode("\n", $raw) as $line) {
            // String literals make this hard to work with.
            // A machine readable representation such as JSON would be easier.
            if (!trim($line)) {
                continue;
            }
            if (preg_match('/.* ; \(lines=[0-9]+, args=[0-9]+, vars=[0-9]+, tmps=[0-9]+/', $line)) {
                if ($current_section) {
                    $sections[] = new SCCPFunction($current_section);
                    $current_section = [];
                }
            }
            $current_section[] = $line;
        }
        if ($current_section) {
            $sections[] = new SCCPFunction($current_section);
            $current_section = [];
        }
        return $sections;
    }

    /**
     * @return array<string,SCCPFunction>
     */
    public static function parse(string $raw) : array {
        $sections = self::parseSections($raw);
        $section_map = [];
        foreach ($sections as $section) {
            if (!$section->valid) {
                continue;
            }
            $section_map[$section->function_name] = $section;
        }
        return $section_map;
    }
}

class SCCPChecker {
    private $php_file_name;

    public function __construct(string $php_file_name) {
        $this->php_file_name = $php_file_name;
    }

    public function run() {
        $php_file_name = $this->php_file_name;
        if (!file_exists($php_file_name)) {
            fwrite(STDERR, "$php_file_name does not exist");
            exit(2);
        }
        $contents = file_get_contents($php_file_name);
        $unoptimized_opcode_promise = new InvokeExecutionPromiseSCCP(PHP_BINARY, $contents, $php_file_name, false);
        $unoptimized_opcode_promise->blockingRead();
        $optimized_opcode_promise   = new InvokeExecutionPromiseSCCP(PHP_BINARY, $contents, $php_file_name, true);

        $optimized_opcode_promise->blockingRead();
        $err1 = $unoptimized_opcode_promise->getError();
        $err2 = $optimized_opcode_promise->getError();
        if ($err1) {
            fwrite(STDERR, "Saw error\n$err1\n");
            exit(3);
        }
        if ($err2) {
            fwrite(STDERR, "Saw error\n$err1\n");
            exit(3);
        }
        $output1 = $unoptimized_opcode_promise->getOutput();
        $output2 = $optimized_opcode_promise->getOutput();

        $unoptimized_map = SCCPHeuristicParser::parse($output1);
        $optimized_map = SCCPHeuristicParser::parse($output2);
        unset($unoptimized_map['$_main']);
        unset($optimized_map['$_main']);
        //var_export($unoptimized_map);
        //var_export($optimized_map);

        $failure = false;
        foreach ($unoptimized_map as $function_name => $unoptimized_function) {
            $optimized_function = $optimized_map[$function_name] ?? null;
            if (!$optimized_function) {
                continue;
            }
            if ($optimized_function->isSimpleReturn() && $unoptimized_function->isDynamic()) {
                printf("WARNING: %s returns a constant in a less than optimal way at %d:%d\n", $function_name, $optimized_function->range[0], $optimized_function->range[1]);
                echo $unoptimized_function->opcodeDump();
                echo "vs optimized\n" . $optimized_function->opcodeDump();
                echo "end\n";
                $failure = true;
            }
        }
        if ($failure) {
            printf("At least one function is a complicated no-op\n");
        }
    }

    public static function main() {
        global $argv;
        if (count($argv) !== 2) {
            echo "Usage: $argv[0] path/to/file_to_analyze.php\n";
            exit(1);
        }
        $runner = new SCCPChecker($argv[1]);
        $runner->run();
    }
}

SCCPChecker::main();
