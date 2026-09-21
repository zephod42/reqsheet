<?php

declare(strict_types=1);

namespace Reqsheet\Fictional;

use DateInterval;
use DateTimeImmutable;
use PDO;
use Reqsheet\Database\DatabaseConfig;
use Reqsheet\Timetable\RecurringLesson;
use Reqsheet\Timetable\TimetableRules;
use Reqsheet\Timetable\TimetableSlot;
use Reqsheet\Timetable\TimetableVersion;
use Reqsheet\Timetable\PdoTimetableGenerationStore;
use RuntimeException;

/**
 * Deliberately Pumba-only, administrator-invoked fictional tenant generator.
 * The authorization and manifest paths are constants so neither can be
 * redirected by an ordinary command-line option.
 */
final class FictionalSchoolGenerator
{
    private const HOSTS = ['pumba'];
    private const DATABASES = ['reqsheet_dev'];
    private const AUTH_FILE = '/etc/reqsheet/reqsheet-fictional-generator.json';
    private const MANIFEST_FILE = '/var/lib/reqsheet/reqsheet-fictional-schools.json';
    private const REFERENCE_DATE = '2026-09-21';

    /** @var array<string, array<string, mixed>> */
    private const PROFILES = [
        'brackenmere' => ['name' => 'Brackenmere Community School', 'admin' => 'EMR', 'teachers' => 6, 'rooms' => 4, 'periods' => 6, 'classes' => ['y7set1','y7set2','y8set1','y8set2','y9set1','y10set1','y11set1']],
        'ashwick' => ['name' => 'Ashwick Hall School', 'admin' => 'JAH', 'teachers' => 14, 'rooms' => 10, 'periods' => 7, 'classes' => ['L4A','L4B','U4A','U4B','L5P','L5C','M5C','M5B','U5A','U5B','L6PH','L6CH','U6BI','U6PH']],
        'fenmere' => ['name' => 'Fenmere Academy', 'admin' => 'RTA', 'teachers' => 30, 'rooms' => 20, 'periods' => 7, 'classes' => ['y7a','y7b','y7c','y7d','y8a','y8b','y8c','y8d','y9a','y9b','y9c','y9d','y10a','y10b','y10c','y10d','y11a','y11b','y11c','y11d','y12bio','y12chem','y12phys','y13bio','y13chem','y13phys','y10triple','y11triple','y12core','y13core']],
    ];

    public static function authPath(): string { return self::AUTH_FILE; }
    public static function manifestPath(): string { return self::MANIFEST_FILE; }
    public static function profiles(): array { return self::PROFILES; }
    public static function referenceDate(): string { return self::REFERENCE_DATE; }

    /** @return array{config:DatabaseConfig,auth:array<string,mixed>,manifest:array<string,mixed>} */
    public static function preflight(array $environment): array
    {
        $host = strtolower((string) gethostname());
        if (!in_array($host, self::HOSTS, true)) throw new RuntimeException('Refusing fictional generation: host is not an approved Pumba host.');
        $config = DatabaseConfig::fromEnvironment($environment);
        if (!in_array($config->name, self::DATABASES, true)) throw new RuntimeException('Refusing fictional generation: database is not the approved Pumba development database.');
        if (!in_array(strtolower($config->host), ['127.0.0.1', 'localhost', 'pumba'], true)) throw new RuntimeException('Refusing fictional generation: database host is not approved for Pumba.');
        if (!is_file(self::AUTH_FILE) || !is_readable(self::AUTH_FILE) || ((fileperms(self::AUTH_FILE) & 0o22) !== 0)) throw new RuntimeException('Refusing fictional generation: protected local Pumba authorization is unavailable or writable by group/other.');
        $auth = json_decode((string) file_get_contents(self::AUTH_FILE), true);
        if (!is_array($auth) || !is_string($auth['execution_authorization'] ?? null) || strlen($auth['execution_authorization']) < 32) throw new RuntimeException('Refusing fictional generation: protected authorization is invalid.');
        if (!is_array($auth['passwords'] ?? null)) throw new RuntimeException('Refusing fictional generation: protected fictional-admin passwords are missing.');
        if (!is_file(self::MANIFEST_FILE) || !is_readable(self::MANIFEST_FILE) || ((fileperms(self::MANIFEST_FILE) & 0o22) !== 0)) throw new RuntimeException('Refusing fictional generation: protected generator manifest is unavailable or writable by group/other.');
        $manifest = json_decode((string) file_get_contents(self::MANIFEST_FILE), true);
        if (!is_array($manifest)) throw new RuntimeException('Refusing fictional generation: generator manifest is invalid.');
        foreach (array_keys(self::PROFILES) as $key) {
            if (!is_string($auth['passwords'][$key] ?? null) || strlen($auth['passwords'][$key]) < 8) throw new RuntimeException('Refusing fictional generation: a fictional administrator password is missing.');
        }
        return ['config' => $config, 'auth' => $auth, 'manifest' => $manifest];
    }

    /** @return array<string, mixed> */
    public function run(PDO $pdo, array $auth, array $manifest, ?string $only, bool $dryRun, bool $regenerate): array
    {
        $keys = $only === null ? array_keys(self::PROFILES) : [$only];
        foreach ($keys as $key) if (!isset(self::PROFILES[$key])) throw new RuntimeException('Unknown fictional school profile.');
        $this->protectManifestTargets($pdo, $manifest);
        $plan = [];
        foreach ($keys as $key) $plan[] = $this->plan($pdo, $key, $manifest, $regenerate);
        if ($dryRun) return ['dry_run' => true, 'schools' => $plan, 'protected_organisations' => $this->protectedCount($pdo, $manifest)];
        foreach ($plan as $item) {
            if (($item['action'] ?? '') === 'noop') continue;
            $this->writeSchool($pdo, $item['key'], self::PROFILES[$item['key']], $auth['passwords'][$item['key']], $item['existing_id'] ?? null, $regenerate);
        }
        return ['dry_run' => false, 'schools' => $plan, 'protected_organisations' => $this->protectedCount($pdo, $manifest)];
    }

    private function protectManifestTargets(PDO $pdo, array $manifest): void
    {
        foreach (($manifest['schools'] ?? []) as $key => $entry) {
            if (!isset(self::PROFILES[$key]) || !is_array($entry) || filter_var($entry['organisation_id'] ?? null, FILTER_VALIDATE_INT) === false) throw new RuntimeException('Refusing generation: manifest contains an unknown or invalid school entry.');
            $q = $pdo->prepare('SELECT name, tenant_slug FROM organisations WHERE id = :id'); $q->execute(['id' => (int) $entry['organisation_id']]); $row = $q->fetch();
            if ($row === false || $row['name'] !== self::PROFILES[$key]['name'] || $row['tenant_slug'] !== $key) throw new RuntimeException('Refusing generation: generator manifest does not match its organisation.');
        }
    }

    private function protectedCount(PDO $pdo, array $manifest): int
    {
        $ids = array_values(array_filter(array_map(static fn ($x) => is_array($x) ? (int) ($x['organisation_id'] ?? 0) : 0, $manifest['schools'] ?? [])));
        $sql = 'SELECT COUNT(*) FROM organisations' . ($ids === [] ? '' : ' WHERE id NOT IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $q = $pdo->prepare($sql); $q->execute($ids); return (int) $q->fetchColumn();
    }

    private function plan(PDO $pdo, string $key, array $manifest, bool $regenerate): array
    {
        $profile = self::PROFILES[$key]; $entry = $manifest['schools'][$key] ?? null;
        $q = $pdo->prepare('SELECT id, name, tenant_slug FROM organisations WHERE tenant_slug = :slug'); $q->execute(['slug' => $key]); $row = $q->fetch();
        if ($row !== false && ($entry === null || (int) $entry['organisation_id'] !== (int) $row['id'])) throw new RuntimeException('Refusing generation: target slug already exists outside the generator manifest.');
        if ($entry !== null && $row === false) throw new RuntimeException('Refusing generation: manifest organisation is missing; refusing to recreate it.');
        return ['key' => $key, 'name' => $profile['name'], 'slug' => $key, 'action' => $row === false ? 'create' : ($regenerate ? 'regenerate' : 'noop'), 'existing_id' => $row === false ? null : (int) $row['id'], 'expected_teachers' => $profile['teachers'], 'expected_rooms' => $profile['rooms'], 'expected_classes' => count($profile['classes'])];
    }

    private function writeSchool(PDO $pdo, string $key, array $profile, string $password, ?int $existingId, bool $regenerate): void
    {
        if (!$pdo->beginTransaction()) throw new RuntimeException('Unable to begin fictional school transaction.');
        try {
            if ($existingId !== null) { if (!$regenerate) throw new RuntimeException('Unexpected existing fictional school state.'); $this->deleteOwnedSchool($pdo, $existingId); }
            $q = $pdo->prepare('INSERT INTO organisations (name, tenant_slug) VALUES (:name, :slug)'); $q->execute(['name' => $profile['name'], 'slug' => $key]); $org = (int) $pdo->lastInsertId();
            $admin = $this->insertUser($pdo, $org, $profile['admin'], ['teacher'], $password, true);
            $teachers = [$admin];
            for ($i = 1; $i < (int) $profile['teachers']; $i++) { $initials = $this->initials($key, $i); $teachers[] = $this->insertUser($pdo, $org, $initials, ['teacher'], null, false); }
            $tech = $this->insertUser($pdo, $org, $this->initials($key, 90), ['technician'], null, false);
            $this->insertSettings($pdo, $org, $profile);
            $rooms = $this->insertRooms($pdo, $org, (int) $profile['rooms']); $classes = $this->insertClasses($pdo, $org, $profile['classes']);
            $version = $this->insertVersionAndSlots($pdo, $org, $profile);
            $lessons = $this->insertLessons($pdo, $org, $version, $teachers, $rooms, $classes, $profile);
            $this->validateWrittenTimetable($pdo, $org, $version['id']);
            $this->activate($pdo, $org, $version['id']);
            $this->insertOccurrencesAndPlans($pdo, $org, $version, $lessons);
            $this->insertRoomPreferences($pdo, $org, $tech, $rooms);
            $pdo->commit();
            $this->updateManifest($key, $org, count($teachers) + 1, count($rooms), count($classes), count($lessons));
        } catch (\Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }

    private function insertUser(PDO $pdo, int $org, string $initials, array $roles, ?string $password, bool $admin): int
    {
        $teacher = in_array('teacher', $roles, true); $tech = in_array('technician', $roles, true); $number = null;
        if ($teacher) { $n = $pdo->prepare('SELECT COALESCE(MAX(teacher_number), 0) + 1 FROM users WHERE organisation_id = :org'); $n->execute(['org' => $org]); $number = (int) $n->fetchColumn(); }
        $q = $pdo->prepare('INSERT INTO users (organisation_id, staff_identifier, operational_role, is_admin, is_teacher, is_technician, teacher_number, password_hash, account_state) VALUES (:org,:initials,:role,:admin,:teacher,:tech,:number,:hash,:state)');
        $q->execute(['org'=>$org,'initials'=>$initials,'role'=>$teacher?'teacher':'technician','admin'=>$admin?1:0,'teacher'=>$teacher?1:0,'tech'=>$tech?1:0,'number'=>$number,'hash'=>$password === null?null:password_hash($password, PASSWORD_DEFAULT),'state'=>$password === null?'awaiting_first_login':'claimed']); return (int)$pdo->lastInsertId();
    }

    private function insertSettings(PDO $pdo, int $org, array $p): void
    { $q=$pdo->prepare('INSERT INTO organisation_settings (organisation_id,working_days,first_day_of_week,periods_per_day,start_time,standard_period_minutes,separators,allow_double_periods,date_format) VALUES (:id,\'1,2,3,4,5\',1,:periods,\'08:30:00\',55,:separators,:double,\'DD/MM/YYYY\')'); $q->execute(['id'=>$org,'periods'=>$p['periods'],'separators'=>json_encode([['after_period'=>2,'type'=>'Break','label'=>'Break','duration_minutes'=>15],['after_period'=>4,'type'=>'Lunchtime','label'=>'Lunch','duration_minutes'=>45]], JSON_THROW_ON_ERROR),'double'=>1]); }
    private function insertRooms(PDO $pdo,int $org,int $count): array { $out=[]; $q=$pdo->prepare('INSERT INTO organisation_rooms (organisation_id,room_code) VALUES (:org,:code)'); for($i=1;$i<=$count;$i++){ $code='S'.str_pad((string)$i,2,'0',STR_PAD_LEFT); $q->execute(['org'=>$org,'code'=>$code]); $out[]=['id'=>(int)$pdo->lastInsertId(),'code'=>$code]; } return $out; }
    private function insertClasses(PDO $pdo,int $org,array $codes): array { $out=[]; $q=$pdo->prepare('INSERT INTO organisation_classes (organisation_id,class_code) VALUES (:org,:code)'); foreach($codes as $code){$q->execute(['org'=>$org,'code'=>$code]);$out[]=['id'=>(int)$pdo->lastInsertId(),'code'=>$code];} return $out; }

    private function insertVersionAndSlots(PDO $pdo,int $org,array $p): array
    { $q=$pdo->prepare('INSERT INTO timetable_versions (organisation_id,label,effective_from,effective_to,first_day_of_week) VALUES (:org,:label,\'2026-08-01\',NULL,1)');$q->execute(['org'=>$org,'label'=>'Autumn 2026']);$vid=(int)$pdo->lastInsertId();$slots=[];$q=$pdo->prepare('INSERT INTO timetable_slots (timetable_version_id,day_of_week,sequence_number,kind,teaching_period_number,label,starts_at,ends_at) VALUES (:v,:day,:seq,:kind,:period,:label,:start,:end)');foreach(range(1,5) as $day){$clock=new DateTimeImmutable('08:30');$seq=1;for($period=1;$period<=$p['periods'];$period++){ $end=$clock->modify('+55 minutes');$q->execute(['v'=>$vid,'day'=>$day,'seq'=>$seq++,'kind'=>'teaching','period'=>$period,'label'=>'P'.$period,'start'=>$clock->format('H:i:s'),'end'=>$end->format('H:i:s')]);$slots[$day][$period]=['id'=>(int)$pdo->lastInsertId(),'period'=>$period];$clock=$end;if($period===2||$period===4){$mins=$period===2?15:45;$end=$clock->modify('+'.$mins.' minutes');$q->execute(['v'=>$vid,'day'=>$day,'seq'=>$seq++,'kind'=>$period===2?'break':'lunch','period'=>null,'label'=>$period===2?'Break':'Lunch','start'=>$clock->format('H:i:s'),'end'=>$end->format('H:i:s')]);$clock=$end;}}}return ['id'=>$vid,'slots'=>$slots]; }

    private function insertLessons(PDO $pdo,int $org,array $version,array $teachers,array $rooms,array $classes,array $p): array
    { $out=[];$q=$pdo->prepare('INSERT INTO recurring_lessons (timetable_version_id,teacher_user_id,day_of_week,start_slot_id,duration_periods,class_code,room_code,class_id,room_id) VALUES (:v,:teacher,:day,:slot,:duration,:class,:room,:class_id,:room_id)');foreach(range(1,5) as $day){for($period=1;$period<=$p['periods'];$period++){if($period===3)continue; $limit=min(count($classes),count($teachers),count($rooms));for($j=0;$j<$limit;$j++){if($period===2 && $j===0)continue;$class=$classes[($j+$day+$period)%count($classes)];$teacher=$teachers[($j+$day*2+$period)%count($teachers)];$room=$rooms[$j%count($rooms)];$duration=($period===1&&$j===0&&$p['periods']>=4)?2:1;$q->execute(['v'=>$version['id'],'teacher'=>$teacher,'day'=>$day,'slot'=>$version['slots'][$day][$period]['id'],'duration'=>$duration,'class'=>$class['code'],'room'=>$room['code'],'class_id'=>$class['id'],'room_id'=>$room['id']]);$out[]=['id'=>(int)$pdo->lastInsertId(),'teacher'=>$teacher,'day'=>$day,'slot'=>$version['slots'][$day][$period]['id'],'duration'=>$duration,'class'=>$class['code'],'room'=>$room['code']];}}}return $out; }

    private function validateWrittenTimetable(PDO $pdo, int $organisationId, int $versionId): void
    {
        $store = new PdoTimetableGenerationStore($pdo);
        $version = $store->findVersion($organisationId, $versionId);
        if ($version === null) throw new RuntimeException('Generated timetable version could not be read back.');
        $validation = TimetableRules::validateLessons($store->lessonsForVersion($versionId), $version, $organisationId, $store->slotsForVersion($versionId), $store->findTeacherOrganisation(...));
        if ($validation['errors'] !== []) throw new RuntimeException('Generated timetable failed application validation: ' . implode(' ', array_unique($validation['errors'])));
    }

    private function activate(PDO $pdo,int $org,int $version):void{$q=$pdo->prepare('UPDATE organisations SET active_timetable_version_id=:v WHERE id=:id');$q->execute(['v'=>$version,'id'=>$org]);}
    private function insertOccurrencesAndPlans(PDO $pdo,int $org,array $version,array $lessons):void
    { $start=new DateTimeImmutable('2026-08-31');$end=new DateTimeImmutable('2026-10-23');$o=$pdo->prepare('INSERT INTO lesson_occurrences (organisation_id,recurring_lesson_id,timetable_version_id,lesson_date,snapshot_teacher_user_id,snapshot_class_code,snapshot_room_code,snapshot_start_slot_id,snapshot_duration_periods) VALUES (:org,:lesson,:version,:date,:teacher,:class,:room,:slot,:duration)');$r=$pdo->prepare('INSERT INTO requisitions (lesson_occurrence_id,state,requirements_text,planning_notes,risk_assessment_text) VALUES (:id,:state,:req,:outline,:risk)');foreach($lessons as $lesson){$date=$start;while($date<=$end){if((int)$date->format('N')===$lesson['day']){$o->execute(['org'=>$org,'lesson'=>$lesson['id'],'version'=>$version['id'],'date'=>$date->format('Y-m-d'),'teacher'=>$lesson['teacher'],'class'=>$lesson['class'],'room'=>$lesson['room'],'slot'=>$lesson['slot'],'duration'=>$lesson['duration']]);$id=(int)$pdo->lastInsertId();$variant=$id%7;$req=['Nothing required','2 microscopes; prepared slides of onion epidermis; iodine solution; coverslips and droppers.','6 low-voltage power supplies, leads, lamps, switches and crocodile clips.','Conical flasks, thermometers, insulation, measuring cylinders and stopwatches.','Burettes, pipettes, standard sodium hydroxide, phenolphthalein and labelled waste beakers.','Use goggles and tie back long hair. Set up the apparatus before the class arrives; check all leads and leave the benches dry.','3 Newton meters, trolleys, runway blocks and masking tape; allow time to reset the runway between groups.'][$variant];$state=$variant===0?'nothing_required':'requirements_entered';$r->execute(['id'=>$id,'state'=>$state,'req'=>$req,'outline'=>['Retrieval practice and a short practical demonstration.','Students record observations in a labelled results table.','Pairs compare measurements and identify sources of uncertainty.','Teacher models the method, then groups complete the investigation.'][$variant%4],'risk'=>$variant===0?'':'Standard department practical precautions; teacher supervises equipment use and students follow the written method.']);}$date=$date->add(new DateInterval('P1D'));}}}
    private function insertRoomPreferences(PDO $pdo,int $org,int $user,array $rooms):void{$q=$pdo->prepare('INSERT INTO technician_room_preferences (organisation_id,user_id,room_id) VALUES (:org,:user,:room)');foreach(array_slice($rooms,0,min(5,count($rooms))) as $room)$q->execute(['org'=>$org,'user'=>$user,'room'=>$room['id']]);}
    private function deleteOwnedSchool(PDO $pdo,int $org):void{$q=$pdo->prepare('DELETE r FROM requisitions r JOIN lesson_occurrences o ON o.id=r.lesson_occurrence_id WHERE o.organisation_id=:id');$q->execute(['id'=>$org]);foreach(['lesson_occurrences','recurring_lessons','technician_room_preferences','timetable_slots','timetable_versions','organisation_classes','organisation_rooms','organisation_settings','users','onboarding_handoffs','account_recovery_flows','account_recovery_rate_limits'] as $table){$q=$pdo->prepare('DELETE FROM '.$table.' WHERE organisation_id=:id'.($table==='timetable_slots'?' AND timetable_version_id IN (SELECT id FROM timetable_versions WHERE organisation_id=:id2)':'').($table==='recurring_lessons'?' AND timetable_version_id IN (SELECT id FROM timetable_versions WHERE organisation_id=:id2)':''));$params=['id'=>$org,'id2'=>$org];try{$q->execute($params);}catch(\PDOException $e){if(in_array($table,['timetable_slots','recurring_lessons'],true)){ $q=$pdo->prepare('DELETE FROM '.$table.' WHERE timetable_version_id IN (SELECT id FROM timetable_versions WHERE organisation_id=:id)');$q->execute(['id'=>$org]);}else throw $e;}}$q=$pdo->prepare('DELETE FROM organisations WHERE id=:id');$q->execute(['id'=>$org]);}
    private function initials(string $key,int $i):string{$sets=['brackenmere'=>['SL','HC','ND','PW','GF','LK','TM','RB'],'ashwick'=>['BC','EF','HS','KM','LV','OD','PN','QR','SW','TY','UG','VI','WX','YZ'],'fenmere'=>['AB','CD','EF','GH','IJ','KL','MN','OP','QR','ST','UV','WX','YZ','AD','BE','CF','DG','EH','FI','GJ','HK','IL','JM','KN','LO','MP','NQ','OR','PS','QT','RU']];$x=$sets[$key][($i-1)%count($sets[$key])];return $x[0].$x[1].chr(65+(($i+ord($x[0]))%26));}
    private function updateManifest(string $key,int $org,int $staff,int $rooms,int $classes,int $lessons):void{$data=is_file(self::MANIFEST_FILE)?json_decode((string)file_get_contents(self::MANIFEST_FILE),true):[];if(!is_array($data))$data=[];$data['generator']='reqsheet-fictional-schools-v1';$data['schools'][$key]=['organisation_id'=>$org,'tenant_slug'=>$key,'generated_at'=>gmdate('c'),'staff'=>$staff,'rooms'=>$rooms,'classes'=>$classes,'recurring_lessons'=>$lessons];$tmp=self::MANIFEST_FILE.'.tmp.'.getmypid();if(file_put_contents($tmp,json_encode($data,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL,LOCK_EX)===false||!rename($tmp,self::MANIFEST_FILE))throw new RuntimeException('School committed but generator manifest could not be updated; stop and have an administrator reconcile it.');}
}
