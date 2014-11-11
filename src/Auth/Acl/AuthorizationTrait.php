<?php namespace Orchestra\Auth\Acl;

use InvalidArgumentException;

trait AuthorizationTrait
{
    /**
     * Auth instance.
     *
     * @var \Orchestra\Contracts\Auth\Guard
     */
    protected $auth;

    /**
     * List of roles.
     *
     * @var \Orchestra\Auth\Acl\Fluent
     */
    protected $roles;

    /**
     * List of actions.
     *
     * @var \Orchestra\Auth\Acl\Fluent
     */
    protected $actions;

    /**
     * List of ACL map between roles and action.
     *
     * @var array
     */
    protected $acl = [];

    /**
     * Verify whether given roles has sufficient roles to access the
     * actions based on available type of access.
     *
     * @param  string|array  $roles      A string or an array of roles
     * @param  string        $action     A string of action name
     * @return bool
     * @throws \InvalidArgumentException
     */
    public function checkAuthorization($roles, $action)
    {
        $action = $this->actions->search($action);

        if (is_null($action)) {
            throw new InvalidArgumentException("Unable to verify unknown action {$action}.");
        }

        foreach ((array) $roles as $role) {
            $role = $this->roles->search($role);

            // array_search() will return false when no key is found based
            // on given haystack, therefore we should just ignore and
            // continue to the next role.
            if (! is_null($role) && isset($this->acl[$role.':'.$action])) {
                return $this->acl[$role.':'.$action];
            }
        }

        return false;
    }

    /**
     * Assign single or multiple $roles + $actions to have access.
     *
     * @param  string|array  $roles      A string or an array of roles
     * @param  string|array  $actions    A string or an array of action name
     * @param  bool          $allow
     * @return void
     * @throws \InvalidArgumentException
     */
    public function setAuthorization($roles, $actions, $allow = true)
    {
        $roles   = $this->roles->filter($roles);
        $actions = $this->actions->filter($actions);

        foreach ($roles as $role) {
            if (! $this->roles->has($role)) {
                throw new InvalidArgumentException("Role {$role} does not exist.");
            }

            $this->groupedAssignAction($role, $actions, $allow);
        }
    }

    /**
     * Grouped assign actions to have access.
     *
     * @param  string  $role
     * @param  array   $actions
     * @param  bool    $allow
     * @return bool
     * @throws \InvalidArgumentException
     */
    protected function groupedAssignAction($role, array $actions, $allow = true)
    {
        foreach ($actions as $action) {
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
     * @param  string  $role       A key or string representation of roles
     * @param  string  $action     A key or string representation of action name
     * @param  bool    $allow
     * @return void
     */
    protected function assign($role = null, $action = null, $allow = true)
    {
        $role = $this->roles->findKey($role);
        $action = $this->actions->findKey($action);

        if (! is_null($role) && ! is_null($action)) {
            $key = $role.':'.$action;
            $this->acl[$key] = $allow;
        }
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
     * @return \Orchestra\Auth\Acl\Fluent
     */
    public function actions()
    {
        return $this->actions;
    }

    /**
     * Get the `roles` instance.
     *
     * @return \Orchestra\Auth\Acl\Fluent
     */
    public function roles()
    {
        return $this->roles;
    }

    /**
     * Get all possible user roles.
     *
     * @return array
     */
    protected function getUserRoles()
    {
        if (! $this->auth->guest()) {
            return $this->auth->roles();
        }

        return $this->roles->has('guest') ? ['guest'] : [];
    }
}
