<?php

/* Copyright (c) 1998-2009 ILIAS open source, Extended GPL, see docs/LICENSE */

/**
 * Description of class
 *
 * @author Stefan Meyer <smeyer.ilias@gmx.de>
 */
class ilConsultationHourUtils
{
    public static function getConsultationHourLinksForRepositoryObject(int $ref_id, int $current_user_id, array $ctrl_class_structure)
    {
        global $DIC;

        $ctrl = $DIC->ctrl();
        $lng = $DIC->language();
        $logger = $DIC->logger()->cal();

        $obj_id = \ilObject::_lookupObjId($ref_id);
        $participants = \ilParticipants::getInstance($ref_id);
        $candidates = array_unique(array_merge(
            $participants->getAdmins(),
            $participants->getTutors()
        ));
        $users = \ilBookingEntry::lookupBookableUsersForObject($obj_id, $candidates);
        $now = new \ilDateTime(time(), IL_CAL_UNIX);
        $links = [];
        foreach ($users as $user_id) {
            $next_entry = null;
            $appointments = \ilConsultationHourAppointments::getAppointments($user_id);
            $category_id = 0;
            foreach ($appointments as $entry) {
                // find next entry
                if (ilDateTime::_before($entry->getStart(), $now, IL_CAL_DAY)) {
                    continue;
                }
                $category_id = ilCalendarCategoryAssignments::_lookupCategories($entry->getEntryId())[0] ?? 0;
                $booking_entry = new ilBookingEntry($entry->getContextId());
                if (!in_array($obj_id, $booking_entry->getTargetObjIds())) {
                    continue;
                }
                if (!$booking_entry->isAppointmentBookableForUser($entry->getEntryId(), $current_user_id)) {
                    continue;
                }
                $next_entry = $entry;
                break;
            }

            $ctrl->setParameterByClass(end($ctrl_class_structure), 'ch_user_id', $user_id);
            if ( $category_id > 0) {
                $ctrl->setParameterByClass(end($ctrl_class_structure), 'category_id', $category_id);
            }
            if ($next_entry instanceof \ilCalendarEntry) {
                $ctrl->setParameterByClass(end($ctrl_class_structure), 'seed', $next_entry->getStart()->get(IL_CAL_DATE));
            }
            $current_link = [
                'link' => $ctrl->getLinkTargetByClass($ctrl_class_structure, 'selectCHCalendarOfUser'),
                'txt' => ilObjUser::_lookupFullname($user_id)
            ];
            $links[] = $current_link;
        }
        // Reset control structure links
        $ctrl->setParameterByClass(end($ctrl_class_structure), 'seed', '');
        $ctrl->setParameterByClass(end($ctrl_class_structure), 'ch_user_id', '');
        $ctrl->setParameterByClass(end($ctrl_class_structure), 'category_id', '');
        return $links;
    }



    /**
     * @param ilBookingEntry $booking
     * @param ilDateTime     $start
     * @param ilDateTime     $end
     * @return int[]
     * @throws ilDatabaseException
     */
    public static function findCalendarAppointmentsForBooking(\ilBookingEntry $booking, \ilDateTime $start, \ilDateTime $end)
    {
        global $DIC;

        $db = $DIC->database();

        $query = 'select ce.cal_id from cal_entries ce ' .
            'join cal_cat_assignments cca on ce.cal_id = cca.cal_id ' .
            'join cal_categories cc on cca.cat_id = cc.cat_id ' .
            'where context_id = ' . $db->quote($booking->getId(), 'integer') . ' ' .
            'and starta = ' . $db->quote($start->get(IL_CAL_DATETIME, '', \ilTimeZone::UTC), \ilDBConstants::T_TIMESTAMP) . ' ' .
            'and enda = ' . $db->quote($end->get(IL_CAL_DATETIME, '', \ilTimeZone::UTC), \ilDBConstants::T_TIMESTAMP) . ' ' .
            'and type = ' . $db->quote(\ilCalendarCategory::TYPE_CH, 'integer');
        $res = $db->query($query);

        $calendar_apppointments = [];
        while ($row = $res->fetchRow(\ilDBConstants::FETCHMODE_OBJECT)) {
            $calendar_apppointments[] = $row->cal_id;
        }
        return $calendar_apppointments;
    }


    /**
     * Book an appointment. All checks (assignment possible, max booking) must be done before
     * @param type $a_usr_id
     * @param type $a_app_id
     * @return bool
     */
    public static function bookAppointment($a_usr_id, $a_app_id)
    {
        global $DIC;

        $lng = $DIC['lng'];

        // Create new default consultation hour calendar
        include_once './Services/Language/classes/class.ilLanguageFactory.php';
        $cal_lang = ilLanguageFactory::_getLanguage($lng->getDefaultLanguage());
        $cal_lang->loadLanguageModule('dateplaner');
        
        include_once './Services/Calendar/classes/class.ilCalendarUtil.php';
        include_once './Services/Calendar/classes/class.ilCalendarCategory.php';
        $ch = ilCalendarUtil::initDefaultCalendarByType(
            ilCalendarCategory::TYPE_CH,
            $a_usr_id,
            $cal_lang->txt('cal_ch_personal_ch'),
            true
        );
        
        // duplicate appointment
        include_once './Services/Calendar/classes/class.ilCalendarEntry.php';
        $app = new ilCalendarEntry($a_app_id);
        $personal_app = clone $app;
        $personal_app->save();

        // assign appointment to category
        include_once './Services/Calendar/classes/class.ilCalendarCategoryAssignments.php';
        $assignment = new ilCalendarCategoryAssignments($personal_app->getEntryId());
        $assignment->addAssignment($ch->getCategoryID());

        // book appointment
        include_once './Services/Booking/classes/class.ilBookingEntry.php';
        $booking = new ilBookingEntry($app->getContextId());
        $booking->book($app->getEntryId(), $a_usr_id);
        return true;
    }
    
    /**
     * Cancel a booking
     * @param type $a_usr_id
     * @param type $a_app_id
     * @return bool
     */
    public static function cancelBooking($a_usr_id, $a_app_id, $a_send_notification = true)
    {
        // Delete personal copy of appointment
        include_once './Services/Calendar/classes/class.ilCalendarEntry.php';
        $app = new ilCalendarEntry($a_app_id);
        
        include_once './Services/Calendar/classes/ConsultationHours/class.ilConsultationHourAppointments.php';
        $user_apps = ilConsultationHourAppointments::getAppointmentIds(
            $a_usr_id,
            $app->getContextId(),
            $app->getStart(),
            ilCalendarCategory::TYPE_CH,
            false
        );
        foreach ($user_apps as $uapp_id) {
            $uapp = new ilCalendarEntry($uapp_id);
            $uapp->delete();

            include_once './Services/Calendar/classes/class.ilCalendarCategoryAssignments.php';
            ilCalendarCategoryAssignments::_deleteByAppointmentId($uapp_id);
            
            break;
        }
        
        // Delete booking entries
        // Send notification
        $booking = new ilBookingEntry($app->getContextId());
        if ($a_send_notification) {
            $booking->cancelBooking($a_app_id, $a_usr_id);
        } else {
            $booking->deleteBooking($a_app_id, $a_usr_id);
        }
        return true;
    }
    
    /**
     * Lookup managed users
     * @param type $a_usr_id
     */
    public static function lookupManagedUsers($a_usr_id)
    {
        global $DIC;

        $ilDB = $DIC['ilDB'];
        
        $query = 'SELECT user_id FROM cal_ch_settings ' .
                'WHERE admin_id = ' . $ilDB->quote($a_usr_id, 'integer');
        $res = $ilDB->query($query);
        
        $users = array();
        while ($row = $res->fetchRow(ilDBConstants::FETCHMODE_OBJECT)) {
            $users[] = $row->user_id;
        }
        return $users;
    }
}
