<?php namespace Orchestra\Auth\Acl;

use InvalidArgumentException;
use RuntimeException;
use Orchestra\Auth\Guard;
use Orchestra\Memory\Abstractable\Container as AbstractableContainer;
use Orchestra\Memory\Provider;
use Orchestra\Support\Str;

class Container extends AbstractableContainer
{
    /**
     * Auth instance.
     *
     * @var \Illuminate\Auth\Guard
     */
    protected $auth = null;

    /**
     * Acl instance name.
     *
     * @var string
     */
    protected $name = null;

    /**
     * List of roles.
     *
     * @var \Orchestra\Auth\Acl\Fluent
     */
    protected $roles = null;

    /**
     * List of actions.
     *
     * @var Fluent
     */
    protected $actions = null;

    /**
     * List of ACL map between roles, action.
     *
     * @var array
     */
    protected $acl = array();

    /**
     * Construct a new object.
     *
     * @param  \Orchestra\Auth\Guard        $auth
     * @param  string                       $name
     * @param  \Orchestra\Memory\Provider   $memory
     */
    public function __construct(Guard $auth, $name, Provider $memory = null)
    {
        $this->auth    = $auth;
        $this->name    = $name;
        $this->roles   = new Fluent('roles');
        $this->actions = new Fluent('actions');

        $this->roles->add('guest');
        $this->attach($memory);
    }

    /**
     * Bind current ACL instance with a Memory instance.
     *
     * @param  \Orchestra\Memory\Provider  $memory
     * @return Container
     * @throws \RuntimeException if `\Orchestra\Memory\Provider` has
     *                           been attached.
     */
    public function attach(Provider $memory = null)
    {
        if ($this->attached() and $memory !== $this->memory) {
            throw new RuntimeException(
                "Unable to assign multiple Orchestra\Memory instance."
            );
        }

        // since we already check instanceof, only check for null.
        if (! is_null($memory)) {
            parent::attach($memory);
            $this->initiate();
        }
    }

    /**
     * Initiate acl data from memory.
     *
     * @return Container
     */
    protected function initiate()
    {
        $data = array('acl' => array(), 'actions' => array(), 'roles' => array());
        $data = array_merge($data, $this->memory->get("acl_".$this->name, array()));

        // Loop through all the roles and actions in memory and add it to
        // this ACL instance.
        $this->roles->attach($data['roles']);
        $this->actions->attach($data['actions']);

        // Loop through all the acl in memory and add it to this ACL
        // instance.
        foreach ($data['acl'] as $id => $allow) {
            list($role, $action) = explode(':', $id);
            $this->assign($role, $action, $allow);
        }

        return $this->sync();
    }

    /**
     * Sync memory with acl instance, make sure anything that added before
     * ->with($memory) got called is appended to memory as well.
     *
     * @return Container
     */
    public function sync()
    {
        // Loop through all the acl in memory and add it to this ACL
        // instance.
        foreach ($this->acl as $id => $allow) {
            list($role, $action) = explode(':', $id);
            $this->assign($role, $action, $allow);
        }

        if (! is_null($this->memory)) {
            $name = $this->name;

            $this->memory->put("acl_{$name}.actions", $this->actions->get());
            $this->memory->put("acl_{$name}.roles", $this->roles->get());
            $this->memory->put("acl_{$name}.acl", $this->acl);
        }

        return $this;
    }

    /**
     * Verify whether current user has sufficient roles to access the
     * actions based on available type of access.
     *
     * @param  string  $action     A string of action name
     * @return boolean
     */
    public function can($action)
    {
        $roles = array();

        if (! $this->auth->guest()) {
            $roles = $this->auth->roles();
        } elseif ($this->roles->has('guest')) {
            array_push($roles, 'guest');
        }

        return $this->check($roles, $action);
    }

    /**
     * Verify whether given roles has sufficient roles to access the
     * actions based on available type of access.
     *
     * @param  string|array     $roles      A string or an array of roles
     * @param  string           $action     A string of action name
     * @return boolean
     * @throws \InvalidArgumentException
     */
    public function check($roles, $action)
    {
        $action = $this->actions->search(Str::slug($action, '-'));

        if (is_null($action)) {
            throw new InvalidArgumentException("Unable to verify unknown action {$action}.");
        }

        foreach ((array) $roles as $role) {
            $role = $this->roles->search(Str::slug($role, '-'));

            // array_search() will return false when no key is found based
            // on given haystack, therefore we should just ignore and
            // continue to the next role.
            if (! is_null($role) and isset($this->acl[$role.':'.$action])) {
                return $this->acl[$role.':'.$action];
            }
        }

        return false;
    }

    /**
     * Assign single or multiple $roles + $actions to have access.
     *
     * @param  string|array     $roles      A string or an array of roles
     * @param  string|array     $actions    A string or an array of action name
     * @param  boolean          $allow
     * @return Container
     * @throws \InvalidArgumentException
     */
    public function allow($roles, $actions, $allow = true)
    {
        $roles   = $this->roles->filter($roles);
        $actions = $this->actions->filter($actions);

        foreach ($roles as $role) {
            $role = Str::slug($role, '-');

            if (! $this->roles->has($role)) {
                throw new InvalidArgumentException("Role {$role} does not exist.");
            }

            $this->groupedAssignAction($role, $actions, $allow);
        }

        return $this->sync();
    }

    /**
     * Grouped assign actions to have access.
     *
     * @param  string  $role
     * @param  array   $actions
     * @param  boolean $allow
     * @return boolean
     * @throws \InvalidArgumentException
     */
    protected function groupedAssignAction($role, array $actions, $allow = true)
    {
        foreach ($actions as $action) {
            $action = Str::slug($action, '-');

            if (! $this->actions->has($action)) {
                throw new InvalidArgumentException("Action {$action} does not exist.");
            }

            $this->assign($role, $action, $allow);
        }

        return true;
    }

    /**
     * Assign a key combination of $roles + $actions to have access.
     *
     * @param  string      $roles      A key or string representation of roles
     * @param  string      $actions    A key or string representation of action name
     * @param  boolean     $allow
     * @return void
     */
    protected function assign($role = null, $action = null, $allow = true)
    {
        if (! (is_numeric($role) and $this->roles->exist($role))) {
            $role = $this->roles->search($role);
        }

        if (! (is_numeric($action) and $this->actions->exist($action))) {
            $action = $this->actions->search($action);
        }

        if (! is_null($role) and ! is_null($action)) {
            $key = $role.':'.$action;
            $this->acl[$key] = $allow;
        }
    }

    /**
     * Shorthand function to deny access for single or multiple
     * $roles and $actions.
     *
     * @param  string|array     $roles      A string or an array of roles
     * @param  string|array     $actions    A string or an array of action name
     * @return Container
     */
    public function deny($roles, $actions)
    {
        return $this->allow($roles, $actions, false);
    }

    /**
     * Forward call to roles or actions.
     *
     * @param  string   $type           'roles' or 'actions'
     * @param  string   $operation
     * @param  array    $parameters
     * @return Fluent
     */
    public function execute($type, $operation, array $parameters = array())
    {
        return call_user_func_array(array($this->{$type}, $operation), $parameters);
    }

    /**
     * Get the `acl` collection.
     *
     * @return array
     */
    public function acl()
    {
        return $this->acl;
    }

    /**
     * Get the `actions` instance.
     *
     * @return Fluent
     */
    public function actions()
    {
        return $this->actions;
    }

    /**
     * Get the `roles` instance.
     *
     * @return Fluent
     */
    public function roles()
    {
        return $this->roles;
    }

    /**
     * Magic method to mimic roles and actions manipulation.
     *
     * @param  string   $method
     * @param  array    $parameters
     * @return mixed
     */
    public function __call($method, array $parameters)
    {
        list($type, $operation) = $this->resolveDynamicExecution($method);

        $response = $this->execute($type, $operation, $parameters);

        if ($operation === 'has') {
            return $response;
        }

        return $this->sync();
    }

    /**
     * Dynamically resolve operation name especially to resolve attach and
     * detach multiple actions or roles.
     *
     * @param  string  $method
     * @return array
     * @throws \InvalidArgumentException
     */
    protected function resolveDynamicExecution($method)
    {
        // Dynamically resolve operation name especially to resolve
        // attach and detach multiple actions or roles.
        $resolveOperation = function ($operation, $multiple) {
            if (! $multiple) {
                return $operation;
            } elseif (in_array($operation, array('fill', 'add'))) {
                return 'attach';
            }

            return 'detach';
        };

        // Preserve legacy CRUD structure for actions and roles.
        $method  = Str::snake($method, '_');
        $matcher = '/^(add|rename|has|get|remove|fill|attach|detach)_(role|action)(s?)$/';

        if (! preg_match($matcher, $method, $matches)) {
            throw new InvalidArgumentException("Invalid keyword [$method]");
        }

        $type      = $matches[2].'s';
        $multiple  = (isset($matches[3]) and $matches[3] === 's');
        $operation = $resolveOperation($matches[1], $multiple);

        return array($type, $operation);
    }
}
