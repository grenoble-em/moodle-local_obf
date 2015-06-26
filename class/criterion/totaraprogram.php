<?php
global $CFG;
require_once __DIR__ . '/item_base.php';

require_once $CFG->dirroot . '/user/lib.php';


class obf_criterion_totaraprogram extends obf_criterion_course {

    protected $criteriatype = obf_criterion_item::CRITERIA_TYPE_TOTARA_PROGRAM;


    protected $required_param = 'program';
    protected $optional_params = array('completedby', 'expiresbycertificate');

    protected $programscache = array();
    protected $certexpirescache = null;


    /**
     * Get the instance of this class by id.
     *
     * @global moodle_database $DB
     * @param int $id The id of the activity criterion
     * @return obf_criterion_activity
     */
    public static function get_instance($id, $method = null) {
        global $DB;

        $record = $DB->get_record('local_obf_criterion_courses', array('id' => $id));
        $obj = new self();
        if ($record) {
            return $obj->populate_from_record($record);
        } else {
            throw new Exception("Trying to get criterion item instance that does not exist.", $id);
        }
        return false;
    }

    /**
     * Initializes this object with values from $record
     *
     * @param \stdClass $record The record from Moodle's database
     * @return \obf_criterion_activity
     */
    public function populate_from_record(\stdClass $record) {
        $this->set_id($record->id)
                ->set_criterionid($record->obf_criterion_id)
                ->set_courseid($record->courseid)
                ->set_completedby($record->completed_by)
                ->set_criteriatype($record->criteria_type);
        // TODO:  params
        return $this;
    }






    /**
     * Returns the name of the activity this criterion is related to.
     *
     * @global moodle_database $DB
     * @param $programids List if ids
     * @return stdClass[] Programs matching ids.
     */
    public static function get_programs_by_id($programids) {
        global $DB;

        $ret = array();
        list($insql, $inparams) = $DB->get_in_or_equal($programids, SQL_PARAMS_NAMED, 'progid');
        $sql = "SELECT * FROM {prog} WHERE id " . $insql;
        $records = $DB->get_records_sql($sql, $inparams);
        foreach ($records as $record) {
            $ret[] = $record;
        }
        return $ret;
    }

    public function get_program_from_cache($progid) {
        global $CFG;
        require_once $CFG->dirroot . '/totara/program/program.class.php';
        if (array_key_exists($progid, $this->programscache)) {
            return $this->programscache[$progid];
        }
        try {
            $program = new program($progid);
            $this->programscache[$progid] = $program;
            return $this->programscache[$progid];
        } catch(Exception $e) {
            debugging($e->getMessage());
        }
        return false;
    }



    /**
     * Returns all programs.
     *
     * @global moodle_database $DB
     * @return stdClass[] Programs.
     */
    public static function get_all_programs() {
        global $DB;

        $ret = array();
        $records = $DB->get_records('prog');
        foreach ($records as $record) {
            $ret[$record->id] = $record;
        }
        return $ret;
    }

    public function get_programids() {
        $params = $this->get_params();
        return array_keys(array_filter($params, function ($v) {
            return array_key_exists('program', $v) ? true : false;
        }));
    }
    protected function get_affected_users() {
        global $DB, $CFG, $ASSIGNMENT_CATEGORY_CLASSNAMES;
        require_once $CFG->dirroot . '/totara/program/program.class.php';
        $programids = $this->get_programids();
        $users = array();
        foreach ($programids as $programid) {
            $program = $this->get_program_from_cache($programid);
            $prog_assignments = $program->get_assignments();
            if ($prog_assignments) {
                $assignments = $prog_assignments->get_assignments();
                foreach ($assignments as $assignment) {
                    $assignments_class = new $ASSIGNMENT_CATEGORY_CLASSNAMES[$assignment->assignmenttype]();
                    $affected_users = $assignments_class->get_affected_users_by_assignment($assignment);
                    foreach ($affected_users as $user) {
                        $users[$user->id] = $user;
                    }
                }
            }
        }
        // Also get email-addresses for users, as they are needed when issuing badges
        $userids = array_map(function ($u) { return $u->id; }, $users);
        list($insql, $inparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'user');
        $sql = "SELECT u.id, u.email FROM {user} AS u WHERE id " . $insql;
        $records = $DB->get_records_sql($sql, $inparams);
        foreach ($records as $record) {
            $users[$record->id]->email = $record->email;
        }
        return array_unique($users, SORT_REGULAR);
    }
    /**
     * Reviews criteria for single user.
     *
     * @global moodle_database $DB
     * @param int $userid The id of the user.
     * @param obf_criterion $criterion The main criterion.
     * @param obf_criterion_item[] $other_items Other items related to main criterion.
     * @param type[] $extra Extra options passed to review method.
     * @return boolean If the course criterion is completed by the user.
     */
    protected function review_for_user($user, $criterion = null, $other_items = null, &$extra = null) {
        global $CFG, $DB;
        require_once $CFG->dirroot . '/totara/program/program.class.php';
        require_once $CFG->dirroot . '/grade/querylib.php';
        require_once $CFG->libdir . '/gradelib.php';
        require_once $CFG->libdir . '/completionlib.php';

        $userid = $user->id;

        $programids = $this->get_programids();
        $criterion = !is_null($criterion) ? $criterion : $this->get_criterion();
        $requireall = $criterion->get_completion_method() == obf_criterion::CRITERIA_COMPLETION_ALL;
        $programscomplete = $requireall; // Default to true when requiring completion of all programs, false if completion of any
        $completedat = false;
        $completedprogramcount = 0;
        foreach ($programids as $programid) {
            $program = $this->get_program_from_cache($programid);
            $certexpires = null;
            if ($this->get_criteriatype() == obf_criterion_item::CRITERIA_TYPE_TOTARA_CERTIF) {
                $certifid = $program->certifid;
                $prog_completion_record = $DB->get_record('certif_completion', array('certifid' => $certifid, 'userid' => $userid));
                $completedat = $prog_completion_record ? $prog_completion_record->timecompleted : time();
                $progcomplete = $prog_completion_record && $prog_completion_record->status == CERTIFSTATUS_COMPLETED;

                if ($progcomplete) {
                    $certification = $DB->get_record('certif', array('id' => $certifid));
                    $lastcompleted = certif_get_content_completion_time($certifid, $userid);
                    $certiftimebase = get_certiftimebase($certification->recertifydatetype, $prog_completion_record->timeexpires, $lastcompleted);
                    $certexpires = get_timeexpires($certiftimebase, $certification->activeperiod);
                }
            } else {
                $prog_completion_record = $DB->get_record('prog_completion', array('programid' => $programid, 'userid' => $userid, 'coursesetid' => 0));
                $completedat = $prog_completion_record ? $prog_completion_record->timecompleted : time();
                $progcomplete = $prog_completion_record && $prog_completion_record->status == STATUS_PROGRAM_COMPLETE;
            }
            if (!$progcomplete) {
                if ($requireall) {
                    return false;
                }
            } else { // User has completed program
                $dateok = !$this->has_prog_completedby($programid) ||
                        $completedat <= $this->get_prog_completedby($programid);
                if (!$dateok) {
                    if ($requireall) {
                        return false;
                    }
                } else {
                    if (!is_null($certexpires)) {
                        $oldval = !is_null($this->certexpirescache) ? $this->certexpirescache : 0;
                        $newval = max($certexpires, $oldval);
                        if ($newval != 0) {
                            $this->certexpirescache = $newval;
                        }
                    }
                    $completedprogramcount += 1;
                }
            }
        }

        if ($completedprogramcount < 1) {
            return false;
        }


        return true;
    }
    public function get_issue_expires_override($user = null) {
        return $this->certexpirescache;
    }
    protected function has_prog_completedby($programid) {
        $params = $this->get_params();
        $prog_params = array_key_exists($programid, $params) ? $params[$programid] : array();
        return array_key_exists('completedby', $prog_params);
    }
    protected function get_prog_completedby($programid) {
        $params = $this->get_params();
        $prog_params = array_key_exists($programid, $params) ? $params[$programid] : array();
        return array_key_exists('completedby', $prog_params) ? $prog_params['completedby'] : -1;
    }
    public function get_name() {
        return 'Program';
    }
    /**
     * Returns this criterion as text, including the name of the course.
     *
     * @return string
     */
    public function get_text() {
        $html = html_writer::tag('strong', $this->get_name());

        if ($this->has_completion_date()) {
            $html .= ' ' . get_string('completedbycriterion', 'local_obf',
                            userdate($this->completedby,
                                    get_string('dateformatdate', 'local_obf')));
        }

        if ($this->has_grade()) {
            $html .= ' ' . get_string('gradecriterion', 'local_obf',
                            $this->grade);
        }

        return $html;
    }
    /**
     * @return array html encoded activity descriptions.
     */
    public function get_text_array() {
        $texts = array();
        $programids = $this->get_programids();
        if (count($programids) == 0) {
            return $texts;
        }
        $programs = self::get_programs_by_id($programids);
        $params = $this->get_params();
        foreach ($programs as $program) {
            $html = html_writer::tag('strong', $program->fullname);
            if (array_key_exists('completedby', $params[$program->id])) {
                $html .= ' ' . get_string('completedbycriterion', 'local_obf',
                                userdate($params[$program->id]['completedby'],
                                        get_string('dateformatdate', 'local_obf')));
            }
            $texts[] = $html;
        }
        return $texts;
    }

    public function requires_field($field) {
        return in_array($field, array_merge(array('criterionid')));
    }
    public function is_reviewable() {
        return $this->criterionid != -1 && count($this->get_programids()) > 0 &&
                $this->criteriatype != obf_criterion_item::CRITERIA_TYPE_UNKNOWN;
    }
    protected function show_review_options() {
        return $this->criterionid != -1;
    }

    /**
     * Print activities to form.
     * @param moodle_form $mform
     * @param type $modules modules so the database is not accessed too much
     * @param type $params
     */
    private function get_form_programs(&$mform, $programs, $params) {
        $mform->addElement('html',html_writer::tag('p', get_string('selectprogram', 'local_obf')));

        $existing = array();
        $completedby = array_map(function($a) {
                    if (array_key_exists('completedby', $a)) {
                        return $a['completedby'];
                    }
                    return false;
                }, $params);
        foreach ($params as $key => $param) {
            if (array_key_exists('program', $param)) {
                $existing[] = $param['program'];
            }
        }

        foreach ($programs as $key => $prog) {
            $mform->addElement('advcheckbox', 'program_' . $key,
                    $prog->fullname, null, array('group' => 1), array(0, $key));
            $mform->addElement('date_selector', 'completedby_' . $key,
                    get_string('activitycompletedby', 'local_obf'),
                    array('optional' => true, 'startyear' => date('Y')));
        }
        foreach ($existing as $progid) {
            $mform->setDefault('program_'.$progid, $progid);
        }
        foreach ($completedby as $key => $value) {
            $mform->setDefault('completedby_'.$key, $value);
        }
    }
    /**
     * Prints criteria activity settings for criteria forms.
     * @param moodle_form $mform
     */
    public function get_options(&$mform) {
        global $OUTPUT;

        $programs = self::get_all_programs();
        $params = $this->get_params();

        $this->get_form_programs($mform, $programs, $params);
    }

    /**
     * Prints required config fields for criteria forms.
     * @param moodle_form $mform
     */
    public function get_form_config(&$mform) {
        global $OUTPUT;
        $crittype = $this->get_criteriatype();
        $mform->addElement('hidden','criteriatype', $crittype);
        $mform->setType('criteriatype', PARAM_INT);

        $mform->createElement('hidden','picktype', 'no');
        $mform->setType('picktype', PARAM_TEXT);
    }
    /**
     * Activities do not support multiple courses.
     * @return boolean false
     */
    public function criteria_supports_multiple_courses() {
        return false;
    }
}