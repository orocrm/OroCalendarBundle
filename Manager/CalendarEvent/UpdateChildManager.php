<?php

namespace Oro\Bundle\CalendarBundle\Manager\CalendarEvent;

use Oro\Bundle\CalendarBundle\Entity\Calendar;
use Oro\Bundle\CalendarBundle\Entity\CalendarEvent;
use Oro\Bundle\CalendarBundle\Entity\Repository\CalendarRepository;
use Oro\Bundle\EntityBundle\ORM\DoctrineHelper;
use Oro\Bundle\OrganizationBundle\Entity\Organization;

/**
 * Responsible to actualize child event state after parent event was updated:
 * - Update child events according to changes in attribute values of parent event.
 * - Update child events according to new state of attendees.
 *
 * Child events is updated in next cases:
 * - event has changes in field and child event should be synced
 * - new attendees added to the event - as a result new child event should correspond to user of the attendee.
 */
class UpdateChildManager
{
    /**
     * @var DoctrineHelper
     */
    protected $doctrineHelper;

    /**
     * @param DoctrineHelper $doctrineHelper
     */
    public function __construct(DoctrineHelper $doctrineHelper)
    {
        $this->doctrineHelper = $doctrineHelper;
    }

    /**
     * Actualize child events state after parent event was updated
     *
     * @param CalendarEvent $calendarEvent Actual calendar event.
     * @param CalendarEvent $originalEvent Actual calendar event.
     * @param Organization  $organization Current organization
     */
    public function onEventUpdate(
        CalendarEvent $calendarEvent,
        CalendarEvent $originalEvent,
        Organization $organization
    ) {
        $attendeeUserIds = $this->getAttendeeUserIds($calendarEvent);
        $calendarUserIds = $this->getAttendeeUserIds($originalEvent);

        $newAttendeeUserIds = array_diff($attendeeUserIds, $calendarUserIds);
        if ($newAttendeeUserIds) {
            $this->createCalendarEventsCopiesForNewAttendees(
                $calendarEvent,
                $originalEvent,
                $organization,
                $newAttendeeUserIds
            );
        }

        $isExceptionalCalendarEvent = !is_null($calendarEvent->getRecurringEvent());
        if ($isExceptionalCalendarEvent) {
            $mainEventAttendeeIds = $this->getAttendeeUserIds($calendarEvent->getRecurringEvent());
            $removedAttendeeUserIds = array_diff($mainEventAttendeeIds, $attendeeUserIds);
            if ($removedAttendeeUserIds) {
                $this->createCalendarEventCopiesForRemovedAttendees(
                    $calendarEvent,
                    $organization,
                    $removedAttendeeUserIds
                );
            }
        }

        $this->updateAttendeesCalendarEvents($calendarEvent, $newAttendeeUserIds);
    }

    /**
     * Get ids of users which related to attendees of this event.
     *
     * @param CalendarEvent $calendarEvent
     *
     * @return array
     */
    protected function getAttendeeUserIds(CalendarEvent $calendarEvent)
    {
        $calendar = $calendarEvent->getCalendar();
        $calendarEventOwnerId = $calendar && $calendar->getOwner()
            ? $calendar->getOwner()->getId()
            : null;

        $result = [];

        $attendees = $calendarEvent->getAttendees();
        foreach ($attendees as $attendee) {
            if (!$attendee->getUser()) {
                continue;
            }

            $userId = $attendee->getUser()->getId();
            if ($calendarEventOwnerId && $calendarEventOwnerId === $userId) {
                continue;
            }

            $result[] = $userId;
        }

        return $result;
    }

    /**
     * @param CalendarEvent $calendarEvent
     * @param CalendarEvent $originalEvent
     * @param Organization  $organization
     * @param array         $newAttendeeUserIds
     */
    protected function createCalendarEventsCopiesForNewAttendees(
        CalendarEvent $calendarEvent,
        CalendarEvent $originalEvent,
        Organization $organization,
        array $newAttendeeUserIds
    ) {
        $newAttendeesCalendars = $this->getUsersDefaultCalendars($newAttendeeUserIds, $organization);
        foreach ($newAttendeesCalendars as $calendar) {
            $attendeeCalendarEvent = $calendarEvent->getChildEventByCalendar($calendar);
            if (!$attendeeCalendarEvent) {
                $attendeeCalendarEvent = $this->createAttendeeCalendarEvent($calendar, $calendarEvent);
                $this->updateAttendeeCalendarEvent($calendarEvent, $attendeeCalendarEvent);
            }

            if ($calendarEvent->getRecurrence()) {
                foreach ($calendarEvent->getRecurringEventExceptions() as $recurringEventException) {
                    $attendeeCalendarEventException = $recurringEventException->getChildEventByCalendar($calendar);
                    /**
                     * Attendee Calendar Event Recurring Event Exception Should be linked
                     * to new Attendee Calendar Event Recurring Event if it was created after exception
                     */
                    if ($attendeeCalendarEventException) {
                        $attendeeCalendarEventException->setRecurringEvent($attendeeCalendarEvent);
                    }

                    $isAttendeesListsChanged = $this->isAttendeesListsChanged($originalEvent, $recurringEventException);
                    if ($isAttendeesListsChanged) {
                        continue;
                    }

                    /**
                     * Create new Attendee Calendar Event Exception or reactivate existing one
                     */
                    if (!$attendeeCalendarEventException) {
                        $attendeeCalendarEventException = $this->createAttendeeCalendarEvent(
                            $calendar,
                            $recurringEventException
                        );
                        $this->updateAttendeeCalendarEvent($recurringEventException, $attendeeCalendarEventException);
                    }
                    $attendeeCalendarEventException->setCancelled($recurringEventException->isCancelled());
                }
            }
        }
    }

    /**
     * @param CalendarEvent $calendarEvent
     * @param CalendarEvent $originalCalendarEvent
     *
     * @return bool
     */
    protected function isAttendeesListsChanged(CalendarEvent $calendarEvent, CalendarEvent $originalCalendarEvent)
    {
        $calendarEventAttendees = $calendarEvent->getAttendees();
        $originalCalendarEventAttendees = $originalCalendarEvent->getAttendees();
        if (count($calendarEventAttendees) != count($originalCalendarEventAttendees)) {
            return true;
        }

        $calendarEventAttendeeIds = [];
        foreach ($calendarEventAttendees as $calendarEventAttendee) {
            $calendarEventAttendeeIds[$calendarEventAttendee->getUser()->getId()] = true;
        }

        foreach ($originalCalendarEventAttendees as $originalCalendarEventAttendee) {
            if (!isset($calendarEventAttendeeIds[$originalCalendarEventAttendee->getUser()->getId()])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param CalendarEvent $calendarEvent
     * @param Organization  $organization
     * @param array         $removedAttendeeUserIds
     */
    protected function createCalendarEventCopiesForRemovedAttendees(
        CalendarEvent $calendarEvent,
        Organization $organization,
        array $removedAttendeeUserIds
    ) {
        $removedAttendeesCalendars = $this->getUsersDefaultCalendars($removedAttendeeUserIds, $organization);
        foreach ($removedAttendeesCalendars as $calendar) {
            $attendeeCalendarEvent = $calendarEvent->getChildEventByCalendar($calendar);
            if (!$attendeeCalendarEvent) {
                $attendeeCalendarEvent = $this->createAttendeeCalendarEvent($calendar, $calendarEvent);
                $attendeeCalendarEvent->setCancelled(true);
            }
        }
    }

    /**
     * @param Calendar $calendar
     * @param CalendarEvent $parent
     *
     * @return CalendarEvent
     */
    protected function createAttendeeCalendarEvent(Calendar $calendar, CalendarEvent $parent)
    {
        $result = new CalendarEvent();
        $result->setCalendar($calendar);
        $result->setParent($parent);
        $parent->addChildEvent($result);
        $result->setRelatedAttendee($result->findRelatedAttendee());

        return $result;
    }

    /**
     * @param int[]        $userIds
     * @param Organization $organization
     *
     * @return Calendar[]
     */
    protected function getUsersDefaultCalendars(array $userIds, Organization $organization)
    {
        /** @var CalendarRepository $calendarRepository */
        $calendarRepository = $this->doctrineHelper->getEntityRepository('OroCalendarBundle:Calendar');

        /** @var Calendar $calendar */
        return $calendarRepository->findDefaultCalendars($userIds, $organization->getId());
    }

    /**
     * Sync Attendee Events state with main event state
     *
     * @param CalendarEvent $calendarEvent
     * @param int[]         $newAttendeeUserIds
     */
    protected function updateAttendeesCalendarEvents(CalendarEvent $calendarEvent, array $newAttendeeUserIds)
    {
        foreach ($calendarEvent->getChildEvents() as $child) {
            $this->updateAttendeeCalendarEvent($calendarEvent, $child);
            if ($child->getCalendar()
                && $child->getCalendar()->getOwner()
                && in_array($child->getCalendar()->getOwner()->getId(), $newAttendeeUserIds)) {
                $child->setCancelled($calendarEvent->isCancelled());
            }
        }
    }

    /**
     * Sync Single Attendee Event state with main event state
     *
     * @param CalendarEvent $parent
     * @param CalendarEvent $child
     */
    protected function updateAttendeeCalendarEvent(CalendarEvent $parent, CalendarEvent $child)
    {
        $child->setTitle($parent->getTitle())
            ->setDescription($parent->getDescription())
            ->setStart($parent->getStart())
            ->setEnd($parent->getEnd())
            ->setAllDay($parent->getAllDay());

        if ($parent->getRecurringEvent()) {
            // This event is an exception of recurring event
            // Get recurring event from calendar of child event
            $childRecurringEvent = $parent->getRecurringEvent()->getChildEventByCalendar($child->getCalendar());

            $child->setRecurringEvent($childRecurringEvent)
                ->setOriginalStart($parent->getOriginalStart());
        }
    }
}
