<?php

namespace ManaPHP\View;

use ManaPHP\Component;
use ManaPHP\View\Flash\AdapterInterface;

/**
 * Class ManaPHP\View\Flash
 *
 * @package flash
 */
abstract class Flash extends Component implements FlashInterface, AdapterInterface
{
    /**
     * @var array
     */
    protected $_cssClasses;

    /**
     * @var string[]
     */
    protected $_messages = [];

    /**
     * \ManaPHP\Flash constructor
     *
     * @param array $cssClasses
     */
    public function __construct($cssClasses = [])
    {
        $this->_cssClasses = $cssClasses ?: [
            'error' => 'flash-error',
            'notice' => 'flash-notice',
            'success' => 'flash-success',
            'warning' => 'flash-warning'
        ];
    }

    public function saveInstanceState()
    {
        return true;
    }

    public function restoreInstanceState($data)
    {
        $this->_messages = [];
    }

    /**
     * Shows a HTML error message
     *
     *<code>
     * $flash->error('This is an error');
     *</code>
     *
     * @param string $message
     *
     * @return void
     */
    public function error($message)
    {
        $this->_message('error', $message);
    }

    /**
     * Shows a HTML notice/information message
     *
     *<code>
     * $flash->notice('This is an information');
     *</code>
     *
     * @param string $message
     *
     * @return void
     */
    public function notice($message)
    {
        $this->_message('notice', $message);
    }

    /**
     * Shows a HTML success message
     *
     *<code>
     * $flash->success('The process was finished successfully');
     *</code>
     *
     * @param string $message
     *
     * @return void
     */
    public function success($message)
    {
        $this->_message('notice', $message);
    }

    /**
     * Shows a HTML warning message
     *
     *<code>
     * $flash->warning('Hey, this is important');
     *</code>
     *
     * @param string $message
     *
     * @return void
     */
    public function warning($message)
    {
        $this->_message('warning', $message);
    }

    /**
     * Prints the messages in the session flasher
     *
     * @param bool $remove
     *
     * @return void
     */
    public function output($remove = true)
    {
        foreach ($this->_messages as $message) {
            echo $message;
        }

        if ($remove) {
            $this->_messages = [];
        }
    }
}