<?php
/** Source ordering intentionally differs from alphabetical and local ID order. */
class Olama_School_Grade {
    public static function get_grades() {
        return array(
            (object) array('id'=>20, 'grade_name'=>'الروضة', 'grade_level'=>-1, 'is_active'=>1),
            (object) array('id'=>8, 'grade_name'=>'الأول الأساسي', 'grade_level'=>1, 'is_active'=>1),
            (object) array('id'=>3, 'grade_name'=>'الثاني الأساسي', 'grade_level'=>2, 'is_active'=>1),
            (object) array('id'=>1, 'grade_name'=>'الثاني الثانوي', 'grade_level'=>12, 'is_active'=>1),
            (object) array('id'=>2, 'grade_name'=>'صف غير نشط', 'grade_level'=>13, 'is_active'=>0),
        );
    }
}
