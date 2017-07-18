<?php

namespace Spatie\GoogleCalendar;

use Carbon\Carbon;
use DateTime;
use Google_Service_Calendar_Event;
use Google_Service_Calendar_EventDateTime;
use Illuminate\Support\Collection;

class Event
{
    /**
     * @var \Google_Service_Calendar_Event
     */
    public $googleEvent;

    /**
     * @var int
     */
    protected $calendarId;

    /**
     * @var array
     */
    protected $attendees;

    public function __construct()
    {
        $this->attendees = [];
        $this->googleEvent = new Google_Service_Calendar_Event;
    }

    /**
     * @param \Google_Service_Calendar_Event $googleEvent
     * @param $calendarId
     *
     * @return static
     */
    public static function createFromGoogleCalendarEvent(Google_Service_Calendar_Event $googleEvent, $calendarId)
    {
        $event = new static;

        $event->googleEvent = $googleEvent;

        $event->calendarId = $calendarId;

        return $event;
    }

    /**
     * @param array $properties
     * @param string|null $calendarId
     *
     * @return mixed
     */
    public static function create(array $properties, string $calendarId = null)
    {
        $event = new static;

        $event->calendarId = static::getGoogleCalendar($calendarId)->getCalendarId();

        foreach ($properties as $name => $value) {
            $event->$name = $value;
        }

        return $event->save('insertEvent');
    }

    /**
     * @param \Carbon\Carbon|null $startDateTime
     * @param \Carbon\Carbon|null $endDateTime
     * @param array $queryParameters
     * @param string|null $calendarId
     *
     * @return \Illuminate\Support\Collection
     */
    public static function get(Carbon $startDateTime = null, Carbon $endDateTime = null, array $queryParameters = [], string $calendarId = null) : Collection
    {
        $googleCalendar = static::getGoogleCalendar($calendarId);

        $googleEvents = $googleCalendar->listEvents($startDateTime, $endDateTime, $queryParameters);

        return collect($googleEvents)
            ->map(function (Google_Service_Calendar_Event $event) use ($calendarId) {
                return static::createFromGoogleCalendarEvent($event, $calendarId);
            })
            ->sortBy(function (Event $event) {
                return $event->sortDate;
            })
            ->values();
    }

    /**
     * @param string $eventId
     * @param string $calendarId
     *
     * @return \Spatie\GoogleCalendar\Event
     */
    public static function find($eventId, $calendarId = null) : Event
    {
        $googleCalendar = static::getGoogleCalendar($calendarId);

        $googleEvent = $googleCalendar->getEvent($eventId);

        return static::createFromGoogleCalendarEvent($googleEvent, $calendarId);
    }

    /**
     * @param string $name
     *
     * @return mixed
     */
    public function __get($name)
    {
        $name = $this->getFieldName($name);

        if ($name === 'sortDate') {
            return $this->getSortDate();
        }

        $value = array_get($this->googleEvent, $name);

        if (in_array($name, ['start.date', 'end.date']) && $value) {
            $value = Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
        }

        if (in_array($name, ['start.dateTime', 'end.dateTime']) && $value) {
            $value = Carbon::createFromFormat(DateTime::RFC3339, $value);
        }

        return $value;
    }

    /**
     * @param $name
     * @param $value
     */
    public function __set($name, $value)
    {
        $name = $this->getFieldName($name);

        if (in_array($name, ['start.date', 'end.date', 'start.dateTime', 'end.dateTime'])) {
            $this->setDateProperty($name, $value);

            return;
        }

        array_set($this->googleEvent, $name, $value);
    }

    /**
     * @return bool
     */
    public function exists() : bool
    {
        return $this->id != '';
    }

    /**
     * @return bool
     */
    public function isAllDayEvent() : bool
    {
        return is_null($this->googleEvent['start']['dateTime']);
    }

    /**
     * @param null $method
     *
     * @return \Spatie\GoogleCalendar\Event
     */
    public function save($method = null) : Event
    {
        $method = $method ?? ($this->exists() ? 'updateEvent' : 'insertEvent');

        $googleCalendar = $this->getGoogleCalendar($this->calendarId);

        $this->googleEvent->setAttendees($this->attendees);

        $googleEvent = $googleCalendar->$method($this);

        return static::createFromGoogleCalendarEvent($googleEvent, $googleCalendar->getCalendarId());
    }

    /**
     * @param string $eventId
     */
    public function delete(string $eventId = null)
    {
        $this->getGoogleCalendar($this->calendarId)->deleteEvent($eventId ?? $this->id);
    }

    /**
     * @param array $attendees
     */
    public function addAttendee(array $attendees)
    {
        $this->attendees[] = $attendees;
    }

    /**
     * @return string
     */
    public function getSortDate() : string
    {
        if ($this->startDate) {
            return $this->startDate;
        }

        if ($this->startDateTime) {
            return $this->startDateTime;
        }

        return '';
    }

    /**
     * @param string $calendarId
     *
     * @return \Spatie\GoogleCalendar\GoogleCalendar
     */
    protected static function getGoogleCalendar($calendarId = null)
    {
        $calendarId = $calendarId ?? config('laravel-google-calendar.calendar_id');

        return GoogleCalendarFactory::createForCalendarId($calendarId);
    }

    /**
     * @param string $name
     * @param \Carbon\Carbon $date
     */
    protected function setDateProperty(string $name, Carbon $date)
    {
        $eventDateTime = new Google_Service_Calendar_EventDateTime;

        if (in_array($name, ['start.date', 'end.date'])) {
            $eventDateTime->setDate($date->format('Y-m-d'));
            $eventDateTime->setTimezone($date->getTimezone());
        }

        if (in_array($name, ['start.dateTime', 'end.dateTime'])) {
            $eventDateTime->setDateTime($date->format(DateTime::RFC3339));
            $eventDateTime->setTimezone($date->getTimezone());
        }

        if (starts_with($name, 'start')) {
            $this->googleEvent->setStart($eventDateTime);
        }

        if (starts_with($name, 'end')) {
            $this->googleEvent->setEnd($eventDateTime);
        }
    }

    /**
     * @param string $name
     *
     * @return string
     */
    protected function getFieldName(string $name) : string
    {
        return [
                   'name' => 'summary',
                   'description' => 'description',
                   'startDate' => 'start.date',
                   'endDate' => 'end.date',
                   'startDateTime' => 'start.dateTime',
                   'endDateTime' => 'end.dateTime',
               ][$name] ?? $name;
    }
}
