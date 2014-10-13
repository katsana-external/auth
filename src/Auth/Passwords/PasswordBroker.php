<?php namespace Orchestra\Auth\Passwords;

use Closure;
use Orchestra\Notifier\Message;
use Orchestra\Notifier\NotifierInterface;
use Illuminate\Auth\UserProviderInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Auth\Passwords\PasswordBroker as Broker;
use Illuminate\Auth\Passwords\ReminderRepositoryInterface;
use Illuminate\Contracts\Auth\Remindable as RemindableContract;
use Illuminate\Contracts\Auth\PasswordBroker as PasswordBrokerContract;

class PasswordBroker extends Broker
{
    /**
     * Create a new password broker instance.
     *
     * @param  \Illuminate\Auth\Passwords\ReminderRepositoryInterface  $reminders
     * @param  \Illuminate\Auth\UserProviderInterface                  $users
     * @param  \Orchestra\Notifier\NotifierInterface                   $mailer
     * @param  string                                                  $reminderView
     */
    public function __construct(
        ReminderRepositoryInterface $reminders,
        UserProviderInterface $users,
        NotifierInterface $mailer,
        $reminderView
    ) {
        $this->users = $users;
        $this->mailer = $mailer;
        $this->reminders = $reminders;
        $this->reminderView = $reminderView;
    }

    /**
     * Send a password reminder to a user.
     *
     * @param  array    $credentials
     * @param  Closure  $callback
     * @return string
     */
    public function remind(array $credentials, Closure $callback = null)
    {
        // First we will check to see if we found a user at the given credentials and
        // if we did not we will redirect back to this current URI with a piece of
        // "flash" data in the session to indicate to the developers the errors.
        $user = $this->getUser($credentials);

        if (is_null($user)) {
            return PasswordBrokerContract::INVALID_USER;
        }

        // Once we have the reminder token, we are ready to send a message out to the
        // user with a link to reset their password. We will then redirect back to
        // the current URI having nothing set in the session to indicate errors.
        $token = $this->reminders->create($user);

        $this->sendReminder($user, $token, $callback);

        return PasswordBrokerContract::REMINDER_SENT;
    }

    /**
     * Send the password reminder e-mail.
     *
     * @param  \Illuminate\Contracts\Auth\Remindable    $user
     * @param  string                                   $token
     * @param  Closure                                  $callback
     * @return \Orchestra\Notifier\Receipt
     */
    public function sendReminder(RemindableContract $user, $token, Closure $callback = null)
    {
        // We will use the reminder view that was given to the broker to display the
        // password reminder e-mail. We'll pass a "token" variable into the views
        // so that it may be displayed for an user to click for password reset.
        $data = array(
            'user'  => ($user instanceof Arrayable ? $user->toArray() : $user),
            'token' => $token,
        );

        $message = Message::create($this->reminderView, $data);

        return $this->mailer->send($user, $message, $callback);
    }
}
