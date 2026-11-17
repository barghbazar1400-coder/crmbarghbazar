<?php
/**
 * Daily recurring task regression using SQLite. Tests real task inserts,
 * one occurrence/day, manager access, edits, soft deletion, and stop-repeat.
 */
define( 'ABSPATH', __DIR__ );
if ( ! extension_loaded( 'pdo_sqlite' ) ) { fwrite( STDERR, "pdo_sqlite required\n" ); exit( 2 ); }
$admin = true; $logged = true; $today = '2026-11-16'; $checks = 0;
$actions = array();
function add_action( $hook, $callback, $priority = 10 ) {}
function add_filter( $hook, $callback ) {}
function is_user_logged_in() { global $logged; return $logged; }
function current_user_can( $cap ) { global $admin; return $admin && $cap === 'manage_options'; }
function get_current_user_id() { return 7; }
function crmb_progress_ready() { return true; }
function get_option( $name, $default = false ) {
    return in_array( $name, array(
        'crmb_work_schema_v1','crmb_task_repeat_schema_v1',
        'crmb_task_plan_schema_v1','crmb_task_plan_repeat_v1'
    ), true ) ? '1' : $default;
}
function absint( $n ) { return abs( (int) $n ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function get_userdata( $id ) { return in_array( (int) $id, array( 7,8,9 ), true ) ? (object) array( 'ID'=>(int)$id,'display_name'=>'Employee' ) : false; }
function crmb_leave_parse_day( $day ) {
    $map = array( '1405-08-25' => '2026-11-16', '1405-08-26' => '2026-11-17',
        '1405-08-27'=>'2026-11-18', '1405-08-20'=>'2026-11-11' );
    return $map[$day] ?? false;
}
function crmb_att_today() { global $today; return $today; }
function crmb_att_timezone() { return new DateTimeZone('Asia/Tehran'); }
function do_action( $hook, ...$args ) { global $actions; $actions[] = array($hook,$args); }
function check( $expected, $actual, $context='' ) {
    global $checks;
    ++$checks;
    if ( $expected !== $actual ) {
        throw new RuntimeException( "Assertion $checks ($context) failed: expected " . var_export( $expected,true ) . ' got ' . var_export( $actual,true ) );
    }
}
class RepeatDB {
    public $prefix = 'wp_'; public $last_error = ''; public $insert_id = 0;
    public PDO $db;
    function __construct() {
        $this->db = new PDO( 'sqlite::memory:' );
        $this->db->setAttribute( PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION );
        $this->db->exec( 'CREATE TABLE wp_crmb_tasks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,title TEXT,description TEXT,
            assigned_to INTEGER,created_by INTEGER,priority TEXT,due_day TEXT,status TEXT,
            created_utc TEXT,updated_utc TEXT,first_started_utc TEXT,
            timer_started_utc TEXT,completed_utc TEXT,tracked_seconds INTEGER DEFAULT 0,
            repeat_series_id INTEGER DEFAULT 0,repeat_day TEXT,deleted_utc TEXT,
            UNIQUE (repeat_series_id,repeat_day)
        );
        CREATE TABLE wp_crmb_task_repeat_series (
            id INTEGER PRIMARY KEY AUTOINCREMENT,title TEXT,description TEXT,assigned_to INTEGER,
            created_by INTEGER,priority TEXT,start_day TEXT,active INTEGER,created_utc TEXT,updated_utc TEXT,plan_template_id INTEGER DEFAULT 0
        );
        CREATE TABLE wp_crmb_task_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,task_id INTEGER,actor_id INTEGER,event_type TEXT,body TEXT,created_utc TEXT
        );
        CREATE TABLE wp_crmb_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,recipient_id INTEGER,sender_id INTEGER,body TEXT,subject TEXT,created_utc TEXT,read_utc TEXT
        );
        CREATE TABLE wp_crmb_task_steps (
            id INTEGER PRIMARY KEY AUTOINCREMENT,task_id INTEGER,kind TEXT,title TEXT,
            assigned_to INTEGER,due_day TEXT,is_done INTEGER DEFAULT 0,
            completed_by INTEGER DEFAULT 0,completed_utc TEXT,created_by INTEGER,
            created_utc TEXT,updated_utc TEXT,deleted_utc TEXT
        );
        CREATE TABLE wp_crmb_task_plan_templates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT,items_json TEXT,created_by INTEGER,created_utc TEXT
        );' );
    }
    function prepare( $sql, ...$args ) {
        foreach ( $args as $arg ) {
            $sql = preg_replace_callback( '/%[ds]/', function ($match) use($arg) {
                return $match[0] === '%d' ? (string)(int)$arg : $this->db->quote((string)$arg);
            }, $sql, 1 );
        }
        return $sql;
    }
    function normalize( $sql ) {
        $sql = preg_replace( '/ FOR UPDATE\b/i','',$sql );
        return str_replace('INSERT IGNORE INTO','INSERT OR IGNORE INTO',$sql);
    }
    function query( $sql ) {
        $this->last_error='';
        try {
            if ($sql === 'START TRANSACTION') $sql='BEGIN TRANSACTION';
            $result=$this->db->exec($this->normalize($sql));
            if(str_starts_with($sql,'INSERT')) $this->insert_id=(int)$this->db->lastInsertId();
            return $result;
        } catch(Throwable $e){$this->last_error=$e->getMessage();return false;}
    }
    function get_row( $sql ) {
        $this->last_error='';
        try {return $this->db->query($this->normalize($sql))->fetch(PDO::FETCH_OBJ)?:null;}
        catch(Throwable $e){$this->last_error=$e->getMessage();return null;}
    }
    function get_results( $sql ) {
        $this->last_error='';
        try {return $this->db->query($this->normalize($sql))->fetchAll(PDO::FETCH_OBJ);}
        catch(Throwable $e){$this->last_error=$e->getMessage();return array();}
    }
    function get_var( $sql ) {
        $this->last_error='';
        try { $r=$this->db->query($this->normalize($sql))->fetchColumn();return $r===false?null:$r; }
        catch(Throwable $e){$this->last_error=$e->getMessage();return null;}
    }
    function insert( $table, $data ) {
        $columns=array_keys($data);
        $sql="INSERT INTO {$table} (".implode(',',$columns).') VALUES ('.implode(',',array_fill(0,count($columns),'?')).')';
        try {
            $stmt=$this->db->prepare($sql);
            $stmt->execute(array_values($data));
            $this->insert_id=(int)$this->db->lastInsertId();
            return $stmt->rowCount();
        } catch(Throwable $e){$this->last_error=$e->getMessage();return false;}
    }
    function update( $table, $fields, $where ) {
        $values=array();$clauses=array();
        foreach($fields as $name=>$value){$clauses[]="{$name}=?";$values[]=$value;}
        $filters=array();
        foreach($where as $name=>$value) {
            if($value===null){$filters[]="{$name} IS NULL";}
            else{$filters[]="{$name}=?";$values[]=$value;}
        }
        $sql="UPDATE {$table} SET ".implode(',',$clauses).' WHERE '.implode(' AND ',$filters);
        try {
            $stmt=$this->db->prepare($sql);
            $stmt->execute($values);
            return $stmt->rowCount();
        } catch(Throwable $e){$this->last_error=$e->getMessage();return false;}
    }
}
$wpdb = new RepeatDB();
function crmb_progress_event( $id, $kind, $body='' ) {
    global $wpdb;
    return false !== $wpdb->insert( 'wp_crmb_task_events', array(
        'task_id'=>$id,'actor_id'=>get_current_user_id(),'event_type'=>$kind,'body'=>$body,
        'created_utc'=>gmdate('Y-m-d H:i:s'),
    ) );
}
require __DIR__ . '/../inc/work.php';
require __DIR__ . '/../inc/work-recurring.php';
require __DIR__ . '/../inc/task-planning.php';

$task = array( 'task'=>'save_task','title'=>'گزارش موجودی روزانه',
    'description'=>'بررسی سیم و کابل', 'assigned_to'=>'8','priority'=>'high','repeat'=>'daily' );
$admin=false;
check('denied',crmb_work_mutate($task),'non manager cannot schedule');
check('denied',crmb_work_mutate(array('task'=>'delete_task','id'=>'1')),'non manager cannot delete');
$admin=true;
check('invalid',crmb_work_mutate(array_replace($task,array('repeat'=>'weekly'))),'invalid recurrence');
check('invalid',crmb_work_mutate(array_replace($task,array('due_day'=>'1405-08-20'))),'past start disallowed');
check('saved',crmb_work_mutate($task),'create daily task');
check(1,(int)$wpdb->get_var("SELECT COUNT(*) FROM wp_crmb_tasks"),'first occurrence');
check(1,(int)$wpdb->get_var("SELECT COUNT(*) FROM wp_crmb_task_repeat_series"),'one template');
check('2026-11-16',$wpdb->get_var("SELECT repeat_day FROM wp_crmb_tasks WHERE id=1"),'first local day');
check('2026-11-16',$wpdb->get_var("SELECT due_day FROM wp_crmb_tasks WHERE id=1"),'first deadline');
check(true,crmb_task_repeat_generate('2026-11-16'),'retry generator');
check(1,(int)$wpdb->get_var("SELECT COUNT(*) FROM wp_crmb_tasks"),'no duplicate');
$wpdb->update('wp_crmb_tasks',array('status'=>'done','completed_utc'=>gmdate('Y-m-d H:i:s')),array('id'=>1));
$today='2026-11-17';
check(true,crmb_task_repeat_generate(),'new day recurrence');
check(2,(int)$wpdb->get_var("SELECT COUNT(*) FROM wp_crmb_tasks"),'new daily occurrence');
$second_id=(int)$wpdb->get_var("SELECT id FROM wp_crmb_tasks WHERE repeat_day='2026-11-17'");
check(true,$second_id>0,'second day instance found');
check('todo',$wpdb->get_var("SELECT status FROM wp_crmb_tasks WHERE id={$second_id}"),'new day starts open');
check('done',$wpdb->get_var("SELECT status FROM wp_crmb_tasks WHERE id=1"),'previous day completed');
check(true,crmb_task_repeat_generate(),'same-day cron retry');
check(2,(int)$wpdb->get_var("SELECT COUNT(*) FROM wp_crmb_tasks"),'exactly one per day');
check('saved',crmb_work_mutate(array_replace($task,array(
    'id'=>(string)$second_id,'title'=>'گزارش اصلاح‌شده','assigned_to'=>'9'
))),'edit repeat instance and future series');
$today='2026-11-18';
check(true,crmb_task_repeat_generate(),'next day after edit');
check(3,(int)$wpdb->get_var("SELECT COUNT(*) FROM wp_crmb_tasks"),'third occurrence');
$third_id=(int)$wpdb->get_var("SELECT id FROM wp_crmb_tasks WHERE repeat_day='2026-11-18'");
check(true,$third_id>0,'third day instance found');
check('گزارش اصلاح‌شده',$wpdb->get_var("SELECT title FROM wp_crmb_tasks WHERE id={$third_id}"),'future carries edit');
check(9,(int)$wpdb->get_var("SELECT assigned_to FROM wp_crmb_tasks WHERE id={$third_id}"),'future assignee updated');
check(8,(int)$wpdb->get_var("SELECT assigned_to FROM wp_crmb_tasks WHERE id=1"),'old history unchanged');
check('saved',crmb_work_mutate(array_replace($task,array(
    'id'=>(string)$third_id,'title'=>'وظیفه مستقل','repeat'=>'none','assigned_to'=>'9'
))),'disable repeat from task editor');
check(0,(int)$wpdb->get_var("SELECT active FROM wp_crmb_task_repeat_series WHERE id=1"),'schedule stopped');
check(0,(int)$wpdb->get_var("SELECT repeat_series_id FROM wp_crmb_tasks WHERE id={$third_id}"),'current task detached');
check(1,(int)$wpdb->get_var("SELECT repeat_series_id FROM wp_crmb_tasks WHERE id=1"),'prior history maintains series');
$today='2026-11-19';
check(true,crmb_task_repeat_generate(),'inactive series skips next day');
check(3,(int)$wpdb->get_var("SELECT COUNT(*) FROM wp_crmb_tasks"),'no new occurrence after stop');
$admin=false;
check('denied',crmb_work_mutate(array_replace($task,array('id'=>(string)$third_id))),'staff cannot edit');
check('denied',crmb_work_mutate(array('task'=>'delete_task','id'=>(string)$third_id,'stop_repeat'=>'1')),'staff cannot delete');
$admin=true;
$wpdb->update('wp_crmb_tasks',array('timer_started_utc'=>gmdate('Y-m-d H:i:s')),array('id'=>$third_id));
check('running',crmb_work_mutate(array('task'=>'delete_task','id'=>(string)$third_id)),'running timer cannot be erased');
$wpdb->update('wp_crmb_tasks',array('timer_started_utc'=>null),array('id'=>$third_id));
check('deleted',crmb_work_mutate(array('task'=>'delete_task','id'=>(string)$third_id)),'soft delete task');
check('cancelled',$wpdb->get_var("SELECT status FROM wp_crmb_tasks WHERE id={$third_id}"),'deleted task closed');
check(true,(bool)$wpdb->get_var("SELECT deleted_utc FROM wp_crmb_tasks WHERE id={$third_id}"),'deleted timestamp retained');
check('changed',crmb_work_mutate(array('task'=>'delete_task','id'=>(string)$third_id)),'double delete blocked');
check(2,(int)$wpdb->get_var("SELECT COUNT(*) FROM wp_crmb_tasks WHERE deleted_utc IS NULL"),'soft deleted excluded');
check('done',$wpdb->get_var("SELECT status FROM wp_crmb_tasks WHERE id=1"),'completed history preserved');
check(true,(int)$wpdb->get_var("SELECT COUNT(*) FROM wp_crmb_task_events WHERE event_type='deleted'") === 1,'audit event kept');
check(true,count(array_filter($actions,fn($v)=>$v[0]==='crmb_task_assigned' && ($v[1][3]??null)===0))===3,'one notification per inserted daily occurrence');

// Deleting a recurring instance can stop the entire series (default UI).
$today='2026-11-20';
$stopTask=array_replace($task,array('title'=>'گزارش روزانه با توقف کامل'));
check('saved',crmb_work_mutate($stopTask),'create additional series');
$stopped_id=(int)$wpdb->get_var("SELECT id FROM wp_crmb_tasks WHERE title='گزارش روزانه با توقف کامل'");
$stopped_series=(int)$wpdb->get_var("SELECT repeat_series_id FROM wp_crmb_tasks WHERE id={$stopped_id}");
check('deleted',crmb_work_mutate(array('task'=>'delete_task','id'=>(string)$stopped_id,'stop_repeat'=>'1')),'manager deletes and stops series');
check(0,(int)$wpdb->get_var("SELECT active FROM wp_crmb_task_repeat_series WHERE id={$stopped_series}"),'deleted series is inactive');
$today='2026-11-21';
check(true,crmb_task_repeat_generate(),'generation after stopped series');
check(1,(int)$wpdb->get_var("SELECT COUNT(*) FROM wp_crmb_tasks WHERE repeat_series_id={$stopped_series}"),'stopped series creates no tomorrow task');

// Manager may deliberately delete only today's occurrence and leave tomorrow scheduled.
$today='2026-11-22';
$continueTask=array_replace($task,array('title'=>'دستور تکراری ادامه‌دار'));
check('saved',crmb_work_mutate($continueTask),'create continuing daily task');
$continuing_id=(int)$wpdb->get_var("SELECT id FROM wp_crmb_tasks WHERE title='دستور تکراری ادامه‌دار'");
$continuing_series=(int)$wpdb->get_var("SELECT repeat_series_id FROM wp_crmb_tasks WHERE id={$continuing_id}");
check('deleted',crmb_work_mutate(array('task'=>'delete_task','id'=>(string)$continuing_id)),"delete only today's occurrence");
check(1,(int)$wpdb->get_var("SELECT active FROM wp_crmb_task_repeat_series WHERE id={$continuing_series}"),'future schedule is still active');
$today='2026-11-23';
check(true,crmb_task_repeat_generate(),'continue tomorrow');
check(2,(int)$wpdb->get_var("SELECT COUNT(*) FROM wp_crmb_tasks WHERE repeat_series_id={$continuing_series}"),"tomorrow instance created despite today's archive");
check(true,crmb_task_repeat_generate(),'duplicate retry after archive');
check(2,(int)$wpdb->get_var("SELECT COUNT(*) FROM wp_crmb_tasks WHERE repeat_series_id={$continuing_series}"),'duplicate never created');

// Manager-selected templates must automatically appear on a new ordinary
// task and on each daily occurrence, without replaying progress or notices.
$wpdb->insert( 'wp_crmb_task_plan_templates', array(
    'name'=>'چک‌لیست روزانه', 'created_by'=>7,
    'items_json'=>json_encode( array(
        array( 'kind'=>'check', 'title'=>'بررسی قیمت‌ها' ),
        array( 'kind'=>'subtask', 'title'=>'ثبت گزارش روزانه' )
    ), JSON_UNESCAPED_UNICODE ), 'created_utc'=>gmdate('Y-m-d H:i:s')
) );
$template_id=(int)$wpdb->insert_id;
$today='2026-11-24';
$daily = array_replace( $task, array(
    'title'=>'روزانه با مراحل آماده', 'plan_template_id'=>(string)$template_id
) );
check( 'saved', crmb_work_mutate( $daily ), 'create daily with template' );
$first_plan_id=(int)$wpdb->get_var(
    "SELECT id FROM wp_crmb_tasks WHERE title='روزانه با مراحل آماده' ORDER BY id DESC LIMIT 1"
);
$plan_series=(int)$wpdb->get_var(
    "SELECT repeat_series_id FROM wp_crmb_tasks WHERE id={$first_plan_id}"
);
check( $template_id, (int)$wpdb->get_var(
    "SELECT plan_template_id FROM wp_crmb_task_repeat_series WHERE id={$plan_series}"
), 'template pinned to daily series' );
check( 2, (int)$wpdb->get_var(
    "SELECT COUNT(*) FROM wp_crmb_task_steps WHERE task_id={$first_plan_id}"
), 'first occurrence gets saved plan' );
check( 8, (int)$wpdb->get_var(
    "SELECT assigned_to FROM wp_crmb_task_steps WHERE task_id={$first_plan_id} ORDER BY id LIMIT 1"
), 'task owner receives copied checklist' );
$wpdb->query( "UPDATE wp_crmb_task_steps SET is_done=1 WHERE task_id={$first_plan_id}" );
$today='2026-11-25';
check( true, crmb_task_repeat_generate(), 'generate second day with plan' );
$second_plan_id=(int)$wpdb->get_var(
    "SELECT id FROM wp_crmb_tasks WHERE repeat_series_id={$plan_series} AND repeat_day='2026-11-25'"
);
check( true, $second_plan_id > 0, 'next daily instance exists' );
check( 2, (int)$wpdb->get_var(
    "SELECT COUNT(*) FROM wp_crmb_task_steps WHERE task_id={$second_plan_id}"
), 'new independent steps each day' );
check( 0, (int)$wpdb->get_var(
    "SELECT SUM(is_done) FROM wp_crmb_task_steps WHERE task_id={$second_plan_id}"
), 'new day starts untouched' );
check( 2, (int)$wpdb->get_var(
    "SELECT SUM(is_done) FROM wp_crmb_task_steps WHERE task_id={$first_plan_id}"
), 'older day retains completion state' );
check( true, crmb_task_repeat_generate(), 'retry same day with plan' );
check( 2, (int)$wpdb->get_var(
    "SELECT COUNT(*) FROM wp_crmb_task_steps WHERE task_id={$second_plan_id}"
), 'cron retry does not duplicate plan' );
$one_off=array_replace( $task, array(
    'repeat'=>'none', 'title'=>'یک‌باره با قالب', 'plan_template_id'=>(string)$template_id
) );
check( 'saved', crmb_work_mutate( $one_off ), 'create one-time task from template' );
$one_id=(int)$wpdb->get_var(
    "SELECT id FROM wp_crmb_tasks WHERE title='یک‌باره با قالب' ORDER BY id DESC LIMIT 1"
);
check( 2, (int)$wpdb->get_var(
    "SELECT COUNT(*) FROM wp_crmb_task_steps WHERE task_id={$one_id}"
), 'ordinary task seeded from template' );
$before_bad=(int)$wpdb->get_var( 'SELECT COUNT(*) FROM wp_crmb_tasks' );
check( 'invalid', crmb_work_mutate( array_replace( $one_off, array(
    'title'=>'قالب گم‌شده', 'plan_template_id'=>'99999'
) ) ), 'unknown template refuses new task' );
check( $before_bad, (int)$wpdb->get_var( 'SELECT COUNT(*) FROM wp_crmb_tasks' ),
    'invalid template does not leave partial task' );

echo "$checks recurring, manager access, duplicate prevention, edit, delete and templated daily checks passed\n";
