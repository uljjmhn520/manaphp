<?php

namespace ManaPHP\Html\Renderer\Engine\Sword;

use ManaPHP\Component;
use ManaPHP\Exception\CreateDirectoryFailedException;
use ManaPHP\Exception\InvalidArgumentException;
use ManaPHP\Exception\RuntimeException;
use ManaPHP\Helper\Str;

/**
 * @property-read \ManaPHP\AliasInterface       $alias
 * @property-read \ManaPHP\Http\UrlInterface    $url
 * @property-read \ManaPHP\Http\RouterInterface $router
 */
class Compiler extends Component
{
    /**
     * @var int
     */
    protected $hash_length = 12;

    /**
     * All custom "directive" handlers.
     *
     * @var array
     */
    protected $directives = [];

    /**
     * Array of opening and closing tags for raw echos.
     *
     * @var array
     */
    protected $rawTags = ['{!!', '!!}'];

    /**
     * Array of opening and closing tags for escaped echos.
     *
     * @var array
     */
    protected $escapedTags = ['{{', '}}'];

    /**
     * @var bool
     */
    protected $foreachelse_used = false;

    /**
     * @var array
     */
    protected $safe_functions
        = [
            'e',
            'url',
            'action',
            'asset',
            'csrf_token',
            'csrf_field',
            'date',
            'html',
            'bundle',
            'attr_nv',
            'attr_inv',
            'partial',
            'json',
            'base_url'
        ];

    /**
     * @param array $options
     */
    public function __construct($options = [])
    {
        if (isset($options['safe_functions'])) {
            if (is_string($options['safe_functions'])) {
                $options['safe_functions'] = preg_split('#[\s,]+#', $options['safe_functions'], PREG_SPLIT_NO_EMPTY);
            }
            $this->safe_functions = array_merge($this->safe_functions, $options['safe_functions']);
        }
    }

    /**
     * @param string $str
     *
     * @return string
     */
    protected function addFileHash($str)
    {
        return preg_replace_callback(
            '#="(/[-\w/.]+\.\w+)"#', function ($match) {
            $url = $match[1];

            if (in_array(pathinfo($url, PATHINFO_EXTENSION), ['htm', 'html', 'php'], true)) {
                return $match[0];
            }

            $path = '@public' . $url;
            $file = $this->alias->resolve($path);
            if (!is_file($file)) {
                return $match[0];
            }
            $hash = substr(md5_file($file), 0, $this->hash_length);

            return "=\"$url?v=$hash\"";
        }, $str
        );
    }

    /**
     * @param string $file
     * @param string $str
     *
     * @return string
     */
    protected function completeRelativeLinks($file, $str)
    {
        if ($str === '#' || str_contains($str, '://') || str_starts_with($str, '//')) {
            return $str;
        }

        if ($str[0] === '/') {
            return $str;
        }

        $area = preg_match('#/Areas/([^/]+)#i', $file, $match) ? Str::snakelize($match[1]) : null;
        if (($pos = strripos($file, '/views/')) === false || strrpos($file, '_layout')) {
            return $str;
        }

        $parts = explode('/', substr($file, $pos + 7));
        if (count($parts) === 1) {
            $controller = Str::snakelize(pathinfo($parts[0], PATHINFO_FILENAME));
        } else {
            $controller = Str::snakelize($parts[0]);
        }
        if (str_contains($str, '/')) {
            $absolute = $area ? "/$area/$str" : "/$str";
        } else {
            $absolute = $area ? "/$area/$controller/$str" : "/$controller/$str";
        }

        return $absolute;
    }

    /**
     * @param string $file
     * @param string $str
     *
     * @return string
     */
    protected function completeLinks($file, $str)
    {
        $str = preg_replace_callback(
            '#\b((?:ajax|axios\.)\w*\\(["\'`])([^/][\w\-/:.]+)#',
            function ($match) use ($file) {
                return $match[1] . $this->completeRelativeLinks($file, $match[2]);
            }, $str
        );

        return $str;
    }

    /**
     * Compile the given Sword template contents.
     *
     * @param string $value
     *
     * @return string
     */
    public function compileString($value)
    {
        $result = '';

        // Here we will loop through all of the tokens returned by the Zend lexer and
        // parse each one into the corresponding valid PHP. We will then have this
        // template as the correctly rendered PHP that can be rendered natively.
        foreach (token_get_all($value) as $token) {
            if (is_array($token)) {
                list($id, $content) = $token;
                if ($id === T_INLINE_HTML) {
                    $content = $this->compileStatements($content);
                    $content = $this->compileComments($content);
                    $content = $this->compileEchos($content);
                }
            } else {
                $content = $token;
            }

            $result .= $content;
        }

        if ($this->hash_length) {
            $result = $this->addFileHash($result);
        }

        return $result;
    }

    /**
     * @param string $source
     * @param string $compiled
     *
     * @return static
     */
    public function compileFile($source, $compiled)
    {
        $source = $this->alias->resolve($source);
        $compiled = $this->alias->resolve($compiled);

        $dir = dirname($compiled);

        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new CreateDirectoryFailedException($dir);
        }

        if (($str = @file_get_contents($source)) === false) {
            $error = error_get_last()['message'] ?? '';
            throw new InvalidArgumentException(['read `%s` sword source file failed: %s', $source, $error]);
        }

        $result = $this->compileString($str);

        $result = $this->completeLinks($source, $result);

        if (file_put_contents($compiled, $result, LOCK_EX) === false) {
            $error = error_get_last()['message'] ?? '';
            throw new RuntimeException(['write `%s` compiled file file failed: %s', $compiled, $error]);
        }

        return $this;
    }

    /**
     * Compile Sword comments into valid PHP.
     *
     * @param string $value
     *
     * @return string
     */
    protected function compileComments($value)
    {
        $pattern = sprintf('/%s--(.*?)--%s/s', $this->escapedTags[0], $this->escapedTags[1]);

        return preg_replace($pattern, '<?php /*$1*/ ?> ', $value);
    }

    /**
     * Compile Sword echos into valid PHP.
     *
     * @param string $value
     *
     * @return string
     */
    protected function compileEchos($value)
    {
        foreach ($this->getEchoMethods() as $method => $length) {
            $value = $this->$method($value);
        }

        return $value;
    }

    /**
     * Get the echo methods in the proper order for compilation.
     *
     * @return array
     */
    protected function getEchoMethods()
    {
        $methods = [
            'compileRawEchos'     => strlen(stripcslashes($this->rawTags[0])),
            'compileEscapedEchos' => strlen(stripcslashes($this->escapedTags[0])),
        ];

        uksort(
            $methods, static function ($method1, $method2) use ($methods) {
            // Ensure the longest tags are processed first
            if ($methods[$method1] > $methods[$method2]) {
                return -1;
            }
            if ($methods[$method1] < $methods[$method2]) {
                return 1;
            }

            // Otherwise give preference to raw tags (assuming they've overridden)
            if ($method1 === 'compileRawEchos') {
                return -1;
            }
            if ($method2 === 'compileRawEchos') {
                return 1;
            }

            if ($method1 === 'compileEscapedEchos') {
                return -1;
            }
            if ($method2 === 'compileEscapedEchos') {
                return 1;
            }

            return 0;
        }
        );

        return $methods;
    }

    /**
     * Compile Sword statements that start with "@".
     *
     * @param string $value
     *
     * @return mixed
     */
    protected function compileStatements($value)
    {
        $callback = function ($match) {
            if (method_exists($this, $method = 'compile_' . $match[1])) {
                $match[0] = $this->$method($match[3] ?? null);
            } elseif (isset($this->directives[$match[1]])) {
                $func = $this->directives[$match[1]];
                $match[0] = $func($match[3] ?? null);
            }

            return isset($match[3]) ? $match[0] : $match[0] . $match[2];
        };

        return preg_replace_callback(
        /** @lang text */ '/\B@(\w+)([ \t]*)(\( ( (?>[^()]+) | (?3) )* \))?/x', $callback,
            $value
        );
    }

    /**
     * Compile the "raw" echo statements.
     *
     * @param string $value
     *
     * @return string
     */
    protected function compileRawEchos($value)
    {
        $pattern = sprintf('/(@)?%s\s*(.+?)\s*%s(\r?\n)?/s', $this->rawTags[0], $this->rawTags[1]);

        $callback = function ($matches) {
            $whitespace = empty($matches[3]) ? '' : $matches[3];

            return $matches[1]
                ? substr($matches[0], 1)
                : '<?= ' . $this->compileEchoDefaults($matches[2]) . '; ?>' . $whitespace;
        };

        return preg_replace_callback($pattern, $callback, $value);
    }

    /**
     * Compile the escaped echo statements.
     *
     * @param string $value
     *
     * @return string
     */
    protected function compileEscapedEchos($value)
    {
        $pattern = sprintf('/(@)?%s\s*(.+?)\s*%s(\r?\n)?/s', $this->escapedTags[0], $this->escapedTags[1]);

        $callback = function ($matches) {
            if ($matches[1]) {
                return substr($matches[0], 1);
            }

            if (preg_match('#^[\w.\[\]"\']+$#', $matches[2]) || preg_match('#^\\$[\w]+\(#', $matches[2])) {
                return $matches[0];
            } elseif ($this->isSafeEchos($matches[2])) {
                return "<?= $matches[2] ?>" . (empty($matches[3]) ? '' : $matches[3]);
            } else {
                return '<?= e(' . $this->compileEchoDefaults($matches[2]) . '); ?>' . (empty($matches[3]) ? ''
                        : $matches[3]);
            }
        };

        return preg_replace_callback($pattern, $callback, $value);
    }

    /**
     * @param string $value
     *
     * @return bool
     */
    protected function isSafeEchos($value)
    {
        return preg_match('#^([a-z\d_]+)\\(#', $value, $match) === 1
            && in_array($match[1], $this->safe_functions, true);
    }

    /**
     * Compile the default values for the echo statement.
     *
     * @param string $value
     *
     * @return string
     */
    protected function compileEchoDefaults($value)
    {
        return preg_replace('/^(?=\\$)(.+?)(?:\s+or\s+)(.+?)$/s', 'isset($1) ? $1 : $2', $value);
    }

    /**
     * Compile the yield statements into valid PHP.
     *
     * @param string $expression
     *
     * @return string
     */
    protected function compile_yield($expression)
    {
        return "<?= \$renderer->getSection{$expression}; ?>";
    }

    /**
     * Compile the section statements into valid PHP.
     *
     * @param string $expression
     *
     * @return string
     */
    protected function compile_section($expression)
    {
        return "<?php \$renderer->startSection{$expression}; ?>";
    }

    /**
     * Compile the append statements into valid PHP.
     *
     * @param string $expression
     *
     * @return string
     */
    protected function compile_append(
        /** @noinspection PhpUnusedParameterInspection */
        $expression
    ) {
        return '<?php $renderer->appendSection(); ?>';
    }

    /**
     * Compile the end-section statements into valid PHP.
     *
     * @param string $expression
     *
     * @return string
     */
    protected function compile_endSection(
        /** @noinspection PhpUnusedParameterInspection */
        $expression
    ) {
        return '<?php $renderer->stopSection(); ?>';
    }

    /**
     * Compile the stop statements into valid PHP.
     *
     * @param string $expression
     *
     * @return string
     */
    protected function compile_stop(
        /** @noinspection PhpUnusedParameterInspection */
        $expression
    ) {
        return '<?php $renderer->stopSection(); ?>';
    }

    /**
     * Compile the else statements into valid PHP.
     *
     * @param string $expression
     *
     * @return string
     */
    protected function compile_else(
        /** @noinspection PhpUnusedParameterInspection */
        $expression
    ) {
        return '<?php else: ?>';
    }

    /**
     * Compile the for statements into valid PHP.
     *
     * @param string $expression
     *
     * @return string
     */
    protected function compile_for($expression)
    {
        return "<?php for{$expression}: ?>";
    }

    /**
     * Compile the foreach statements into valid PHP.
     *
     * @param string $expression
     *
     * @return string
     */
    protected function compile_foreach($expression)
    {
        return "<?php \$index = -1; foreach{$expression}: \$index++; ?>";
    }

    /**
     * Compile the foreachelse statements into valid PHP.
     *
     * @return string
     */
    protected function compile_foreachElse()
    {
        $this->foreachelse_used = true;
        return '<?php endforeach; ?> <?php if($index === -1): ?>';
    }

    /**
     * Compile the can statements into valid PHP.
     *
     * @param string $expression
     *
     * @return string
     */
    protected function compile_can($expression)
    {
        return "<?php if (container('authorization')->isAllowed{$expression}): ?>";
    }

    /**
     * Compile the allow statements into valid PHP.
     *
     * @param string $expression
     *
     * @return string
     */
    protected function compile_allow($expression)
    {
        $parts = explode(',', substr($expression, 1, -1));
        $expr = $this->compileString($parts[1]);
        return "<?php if (container('authorization')->isAllowed($parts[0])): ?>$expr<?php endif ?>";
    }

    /**
     * Compile the cannot statements into valid PHP.
     *
     * @param string $expression
     *
     * @return string
     */
    protected function compile_cannot($expression)
    {
        return "<?php if (!container('authorization')->isAllowed{$expression}): ?>";
    }

    /**
     * Compile the if statements into valid PHP.
     *
     * @param string $expression
     *
     * @return string
     */
    protected function compile_if($expression)
    {
        return "<?php if{$expression}: ?>";
    }

    /**
     * Compile the else-if statements into valid PHP.
     *
     * @param string $expression
     *
     * @return string
     */
    protected function compile_elseif($expression)
    {
        return "<?php elseif{$expression}: ?>";
    }

    /**
     * Compile the while statements into valid PHP.
     *
     * @param string $expression
     *
     * @return string
     */
    protected function compile_while($expression)
    {
        return "<?php while{$expression}: ?>";
    }

    /**
     * Compile the end-while statements into valid PHP.
     *
     * @param string $expression
     *
     * @return string
     */
    protected function compile_endWhile(
        /** @noinspection PhpUnusedParameterInspection */
        $expression
    ) {
        return '<?php endwhile; ?>';
    }

    /**
     * Compile the end-for statements into valid PHP.
     *
     * @param string $expression
     *
     * @return string
     */
    protected function compile_endFor(
        /** @noinspection PhpUnusedParameterInspection */
        $expression
    ) {
        return '<?php endfor; ?>';
    }

    /**
     * Compile the end-for-each statements into valid PHP.
     *
     * @param string $expression
     *
     * @return string
     */
    protected function compile_endForeach(
        /** @noinspection PhpUnusedParameterInspection */
        $expression
    ) {
        $r = $this->foreachelse_used ? '<?php endif; ?>' : '<?php endforeach; ?>';
        $this->foreachelse_used = false;
        return $r;
    }

    /**
     * Compile the end-can statements into valid PHP.
     *
     * @param string $expression
     *
     * @return string
     */
    protected function compile_endCan(
        /** @noinspection PhpUnusedParameterInspection */
        $expression
    ) {
        return '<?php endif; ?>';
    }

    /**
     * Compile the end-cannot statements into valid PHP.
     *
     * @param string $expression
     *
     * @return string
     */
    protected function compile_endCannot(
        /** @noinspection PhpUnusedParameterInspection */
        $expression
    ) {
        return '<?php endif; ?>';
    }

    /**
     * Compile the end-if statements into valid PHP.
     *
     * @param string $expression
     *
     * @return string
     */
    protected function compile_endIf(
        /** @noinspection PhpUnusedParameterInspection */
        $expression
    ) {
        return '<?php endif; ?>';
    }

    /**
     * Compile the include statements into valid PHP.
     *
     * @param string $expression
     *
     * @return string
     */
    protected function compile_include($expression)
    {
        return "<?php \$renderer->partial{$expression} ?>";
    }

    /**
     * Compile the partial statements into valid PHP.
     *
     * @param string $expression
     *
     * @return string
     */
    protected function compile_partial($expression)
    {
        return "<?php \$renderer->partial{$expression} ?>";
    }

    /**
     * Compile the block statements into valid PHP.
     *
     * @param string $expression
     *
     * @return string
     */
    protected function compile_block($expression)
    {
        return "<?php container('view')->block{$expression} ?>";
    }

    /**
     * Compile the break statements into valid PHP.
     *
     * @param string $expression
     *
     * @return string
     */
    protected function compile_break($expression)
    {
        return $expression ? "<?php if{$expression} break; ?>" : '<?php break; ?>';
    }

    /**
     * Compile the break statements into valid PHP.
     *
     * @param string $expression
     *
     * @return string
     */
    protected function compile_continue($expression)
    {
        return $expression ? "<?php if{$expression} continue; ?>" : '<?php continue; ?>';
    }

    /**
     * Compile the maxAge statements into valid PHP.
     *
     * @param string $expression
     *
     * @return string
     */
    protected function compile_maxAge($expression)
    {
        return "<?php container('view')->setMaxAge{$expression}; ?>";
    }

    /**
     * Compile the break statements into valid PHP.
     *
     * @param string $expression
     *
     * @return string
     */
    protected function compile_layout($expression)
    {
        return "<?php container('view')->setLayout{$expression}; ?>";
    }

    /**
     * Compile the break statements into valid PHP.
     *
     * @param string $expression
     *
     * @return string
     */
    protected function compile_content(
        /** @noinspection PhpUnusedParameterInspection */
        $expression
    ) {
        return "<?= container('view')->getContent(); ?>";
    }

    /**
     * Compile the break statements into valid PHP.
     *
     * @param string $expression
     *
     * @return string
     */
    protected function compile_php($expression)
    {
        if ($expression[0] === '(') {
            $expression = (string)substr($expression, 1, -1);
        }

        return $expression ? "<?php {$expression}; ?>" : '<?php ';
    }

    /**
     * Compile the break statements into valid PHP.
     *
     * @param string $expression
     *
     * @return string
     */
    protected function compile_endPhp(
        /** @noinspection PhpUnusedParameterInspection */
        $expression
    ) {
        return ' ?>';
    }

    /**
     * Compile the widget statements into valid PHP.
     *
     * @param string $expression
     *
     * @return string
     */
    protected function compile_widget($expression)
    {
        return "<?php container('view')->widget{$expression}; ?>";
    }

    /**
     * Compile the Url statements into valid PHP.
     *
     * @param string $expression
     *
     * @return string
     */
    protected function compile_url($expression)
    {
        if (strcspn($expression, '$\'"') === strlen($expression)) {
            $expression = '(\'' . trim($expression, '()') . '\')';
        }

        return "<?= url{$expression}; ?>";
    }

    /**
     * Compile the Asset statements into valid PHP.
     *
     * @param string $expression
     *
     * @return string
     */
    protected function compile_asset($expression)
    {
        if (strcspn($expression, '$\'"') === strlen($expression)) {
            $expression = '(\'' . trim($expression, '()') . '\')';
        }

        return asset(substr($expression, 2, -2));
        /*return "<?= asset{$expression}; ?>";*/
    }

    /**
     * Compile the flash statements into valid PHP.
     *
     * @param string $expression
     *
     * @return string
     */
    protected function compile_flash(
        /** @noinspection PhpUnusedParameterInspection */
        $expression
    ) {
        return "<?php container('flash')->output() ?>";
    }

    /**
     * Compile the json statements into valid PHP.
     *
     * @param string $expression
     *
     * @return string
     */
    protected function compile_json($expression)
    {
        $expression = (string)substr($expression, 1, -1);
        return "<?= json_stringify({$expression}) ;?>";
    }

    /**
     * Compile the json statements into valid PHP.
     *
     * @param string $expression
     *
     * @return string
     */
    protected function compile_debugger(
        /** @noinspection PhpUnusedParameterInspection */
        $expression
    ) {
        return '<?php if(container("response")->hasHeader("X-Debugger-Link")){?><div class="debugger"><a target="_self" href="'
            . '<?= container("response")->getHeader("X-Debugger-Link") ?>">Debugger</a></div><?php }?> ';
    }

    /**
     * Compile the eol statements into valid PHP.
     *
     * @param string $expression
     *
     * @return string
     */
    protected function compile_eol(
        /** @noinspection PhpUnusedParameterInspection */
        $expression
    ) {
        return '<?= PHP_EOL ?>';
    }

    /**
     * Compile the eol statements into valid PHP.
     *
     * @param string $expression
     *
     * @return string
     */
    protected function compile_date($expression)
    {
        $time = substr($expression, 1, -1);
        return "<?= date('Y-m-d H:i:s', $time) ?>";
    }

    /**
     * Compile the action statements into valid PHP.
     *
     * @param string $expression
     *
     * @return string
     */
    protected function compile_action($expression)
    {
        if (preg_match('#^\\(([\'"]?)([/_a-z\d]+)\1\\)$#i', $expression, $match)) {
            return action($match[2]);
        } else {
            return "<?= action{$expression} ?>";
        }
    }

    /**
     * @param string $expression
     *
     * @return string
     */
    protected function compile_use($expression)
    {
        return '<?php use ' . substr($expression, 1, -1) . ';?>';
    }

    /**
     * @param string $expression
     *
     * @return string
     */
    protected function compile_html($expression)
    {
        return "<?= html{$expression}; ?>";
    }

    /**
     * @return string
     */
    protected function compile_css()
    {
        return "<?php \$renderer->startSection('css'); ?>";
    }

    /**
     * @return string
     */
    protected function compile_endcss()
    {
        return '<?php $renderer->appendSection(); ?>';
    }

    /**
     * @return string
     */
    protected function compile_js()
    {
        return "<?php \$renderer->startSection('js'); ?>";
    }

    /**
     * @return string
     */
    protected function compile_endjs()
    {
        return '<?php $renderer->appendSection(); ?>';
    }

    /**
     * Register a handler for custom directives.
     *
     * @param string   $name
     * @param callable $handler
     *
     * @return static
     */
    public function directive($name, callable $handler)
    {
        $this->directives[$name] = $handler;

        return $this;
    }
}