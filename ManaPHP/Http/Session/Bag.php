<?php

namespace ManaPHP\Http\Session;

use ManaPHP\Component;

/**
 * Class ManaPHP\Http\Session\Bag
 *
 * @package ManaPHP\Http\Session
 *
 * @property \ManaPHP\Http\SessionInterface $session
 */
class Bag extends Component implements BagInterface
{
    /**
     * @var string
     */
    protected $_name;

    /**
     * \ManaPHP\Session\Bag constructor
     *
     * @param string $name
     *
     */
    public function __construct($name)
    {
        $this->_name = $name;
    }

    /**
     * Destroys the session bag
     *
     *<code>
     * $user->destroy();
     *</code>
     *
     */
    public function destroy()
    {
        $this->session->remove($this->_name);
    }

    /**
     * Sets a value in the session bag
     *
     *<code>
     * $user->set('name', 'Kimbra');
     *</code>
     *
     * @param string $property
     * @param mixed  $value
     *
     */
    public function set($property, $value)
    {
        $defaultCurrentValue = [];
        $data = $this->session->get($this->_name, $defaultCurrentValue);
        $data[$property] = $value;

        $this->session->set($this->_name, $data);
    }

    /**
     * Obtains a value from the session bag optionally setting a default value
     *
     *<code>
     * echo $user->get('name', 'Kimbra');
     *</code>
     *
     * @param string $property
     * @param string $defaultValue
     *
     * @return mixed
     */
    public function get($property = null, $defaultValue = null)
    {
        $defaultCurrentValue = [];
        $data = $this->session->get($this->_name, $defaultCurrentValue);

        if ($property === null) {
            return $data;
        } else {
            return isset($data[$property]) ? $data[$property] : $defaultValue;
        }
    }

    /**
     * Check whether a property is defined in the internal bag
     *
     *<code>
     * var_dump($user->has('name'));
     *</code>
     *
     * @param string $property
     *
     * @return bool
     */
    public function has($property)
    {
        $defaultCurrentValue = [];
        $data = $this->session->get($this->_name, $defaultCurrentValue);

        return isset($data[$property]);
    }

    /**
     * Removes a property from the internal bag
     *
     *<code>
     * $user->remove('name');
     *</code>
     *
     * @param string $property
     *
     * @return void
     */
    public function remove($property)
    {
        $defaultCurrentValue = [];
        $data = $this->session->get($this->_name, $defaultCurrentValue);
        unset($data[$property]);

        $this->session->set($this->_name, $data);
    }

    /**
     * @return array
     */
    public function dump()
    {
        $defaultCurrentValue = [];

        $data = parent::dump();
        $data['_data'] = $this->session->get($this->_name, $defaultCurrentValue);

        return $data;
    }
}