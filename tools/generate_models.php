<?php

/** Генератор Enums и Eloquent-моделей CorpMS (v3). Формат: kind:Class:method:fk, mt:Class:method:pivot:fk:rk */

declare(strict_types=1);

$base = dirname(__DIR__);
$enumDir = $base . '/app/Shared/Enums';
$modelDir = $base . '/app/Models';

@mkdir($enumDir, 0777, true);
@mkdir($modelDir, 0777, true);

/* ------------------------- Enums ------------------------- */

$enums = [
    'PermissionScope' => [['Own', 'own'], ['Department', 'department'], ['Company', 'company']],
    'TaskType' => [['Task', 'task'], ['Order', 'order'], ['DocumentTask', 'document_task'], ['ApprovalTask', 'approval_task'], ['DocumentPrep', 'document_prep'], ['DocumentSign', 'document_sign'], ['DocumentReview', 'document_review'], ['Service', 'service']],
    'TaskPriority' => [['Low', 'low'], ['Medium', 'medium'], ['High', 'high'], ['Critical', 'critical']],
    'TaskStatus' => [['New', 'new'], ['Planned', 'planned'], ['InProgress', 'in_progress'], ['Review', 'review'], ['Returned', 'returned'], ['Completed', 'completed'], ['Cancelled', 'cancelled'], ['Overdue', 'overdue']],
    'TaskAssignmentRole' => [['Assignee', 'assignee'], ['Coassignee', 'coassignee']],
    'TaskAssignmentStatus' => [['Pending', 'pending'], ['Accepted', 'accepted'], ['InProgress', 'in_progress'], ['Review', 'review'], ['Completed', 'completed'], ['Returned', 'returned'], ['Rejected', 'rejected']],
    'AssigneeType' => [['Employee', 'employee'], ['Department', 'department']],
    'DocumentStatus' => [['Draft', 'draft'], ['Submitted', 'submitted'], ['Review', 'review'], ['Revision', 'revision'], ['Approved', 'approved'], ['Signing', 'signing'], ['Signed', 'signed'], ['Registered', 'registered'], ['Archived', 'archived'], ['Rejected', 'rejected']],
    'DocumentType' => [['Incoming', 'incoming'], ['Outgoing', 'outgoing'], ['Internal', 'internal']],
    'Confidentiality' => [['Public', 'public'], ['Internal', 'internal'], ['Confidential', 'confidential'], ['Secret', 'secret']],
    'DocumentPriority' => [['Low', 'low'], ['Medium', 'medium'], ['High', 'high'], ['Critical', 'critical']],
    'ApprovalStatus' => [['Pending', 'pending'], ['Approved', 'approved'], ['Rejected', 'rejected'], ['RevisionRequested', 'revision_requested'], ['Skipped', 'skipped']],
    'SignatureDriver' => [['Internal', 'internal'], ['QrApproval', 'qr_approval'], ['Certificate', 'certificate'], ['ExternalQualified', 'external_qualified']],
    'SignatureRequestStatus' => [['Pending', 'pending'], ['AwaitingConfirmation', 'awaiting_confirmation'], ['Signed', 'signed'], ['Declined', 'declined'], ['Revision', 'revision'], ['Cancelled', 'cancelled'], ['Partial', 'partial']],
    'SignatureStatus' => [['Valid', 'valid'], ['Revoked', 'revoked'], ['Invalid', 'invalid']],
    'AttendanceSource' => [['Biometric', 'biometric'], ['Qr', 'qr'], ['Kiosk', 'kiosk'], ['Manual', 'manual']],
    'AttendanceStatus' => [['OnTime', 'on_time'], ['Late', 'late'], ['Absent', 'absent'], ['EarlyLeave', 'early_leave'], ['Overtime', 'overtime'], ['Present', 'present'], ['Weekend', 'weekend'], ['Holiday', 'holiday']],
    'AnomalyType' => [['MissingCheckout', 'missing_checkout'], ['NoShow', 'no_show'], ['Duplicate', 'duplicate'], ['DirectionMismatch', 'direction_mismatch'], ['Unrecognized', 'unrecognized']],
    'AnomalyStatus' => [['Open', 'open'], ['Resolved', 'resolved'], ['Ignored', 'ignored']],
    'CorrectionStatus' => [['Pending', 'pending'], ['Approved', 'approved'], ['Rejected', 'rejected']],
    'DeviceDirection' => [['In', 'in'], ['Out', 'out'], ['Both', 'both']],
    'DeviceStatus' => [['Online', 'online'], ['Offline', 'offline'], ['Disabled', 'disabled']],
    'BiometricEventStatus' => [['Pending', 'pending'], ['Processed', 'processed'], ['Failed', 'failed'], ['Skipped', 'skipped']],
    'BiometricProfileStatus' => [['Pending', 'pending'], ['Enrolled', 'enrolled'], ['Disabled', 'disabled'], ['Deleted', 'deleted']],
    'EnrollmentStatus' => [['Pending', 'pending'], ['Completed', 'completed'], ['Failed', 'failed']],
    'LeaveType' => [['Vacation', 'vacation'], ['Sick', 'sick'], ['BusinessLeave', 'business_leave'], ['DayOff', 'day_off'], ['Other', 'other']],
    'LeaveStatus' => [['Pending', 'pending'], ['Approved', 'approved'], ['Rejected', 'rejected'], ['Cancelled', 'cancelled']],
    'ScheduleType' => [['Standard', 'standard'], ['Flexible', 'flexible'], ['Shift', 'shift'], ['Custom', 'custom']],
    'EmployeeStatus' => [['Active', 'active'], ['Inactive', 'inactive'], ['Dismissed', 'dismissed']],
    'WorkflowActorType' => [['Role', 'role'], ['Department', 'department'], ['Employee', 'employee'], ['Author', 'author'], ['Ceo', 'ceo']],
    'WorkflowAction' => [['Approve', 'approve'], ['Review', 'review'], ['Sign', 'sign']],
    'WorkflowState' => [['NotStarted', 'not_started'], ['InProgress', 'in_progress'], ['Completed', 'completed'], ['Failed', 'failed']],
    'NotificationChannel' => [['App', 'app'], ['Telegram', 'telegram'], ['Email', 'email']],
    'QrKind' => [['Profile', 'profile'], ['Badge', 'badge'], ['Card', 'card'], ['Signing', 'signing']],
    'QrStatus' => [['Active', 'active'], ['Revoked', 'revoked'], ['Blocked', 'blocked']],
];

$ns = 'App\\Shared\\Enums';
foreach ($enums as $name => $cases) {
    $lines = [];
    foreach ($cases as [$case, $value]) {
        $lines[] = "    case {$case} = '{$value}';";
    }
    $body = "enum {$name}: string\n{\n" . implode("\n", $lines) . "\n}\n";
    file_put_contents($enumDir . '/' . $name . '.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$ns};\n\n" . $body);
}

/* ------------------------- Models ------------------------- */

$models = [
    'Company' => [
        'table' => 'companies',
        'casts' => ['is_active' => 'bool', 'settings' => 'array'],
        'relations' => 'h:Department:departments:company_id | h:Position:positions:company_id | h:Employee:employees:company_id | h:WorkSchedule:workSchedules:company_id',
    ],
    'Department' => [
        'table' => 'departments',
        'casts' => ['is_active' => 'bool'],
        'relations' => 'b:Company:company:company_id | b:Department:parent:parent_id | h:Department:children:parent_id | h:Employee:employees:department_id',
    ],
    'Position' => [
        'table' => 'positions',
        'relations' => 'b:Company:company:company_id | h:Employee:employees:position_id',
    ],
    'Employee' => [
        'table' => 'employees',
        'casts' => ['status' => 'EmployeeStatus', 'hired_at' => 'date', 'fired_at' => 'date', 'birth_date' => 'date'],
        'softDeletes' => true,
        'relations' => 'b:Company:company:company_id | b:Department:department:department_id | b:Position:position:position_id | b:User:user:user_id | b:Employee:manager:manager_id | h:Employee:subordinates:manager_id | mt:Role:roles:employee_roles:employee_id:role_id | h:EmployeePermissionOverride:permissionOverrides:employee_id | o:WorkSchedule:workSchedule:work_schedule_id | h:AttendanceRecord:attendanceRecords:employee_id | h:LeaveRequest:leaveRequests:employee_id | h:TaskAssignment:taskAssignments:employee_id | h:Document:documents:author_id | h:DocumentSignature:signatures:signer_id | h:QrCode:qrCodes:employee_id | h:BiometricProfile:biometricProfiles:employee_id | h:AppNotification:notifications:recipient_id | h:ScheduleAssignment:scheduleAssignments:employee_id',
    ],
    'Role' => [
        'table' => 'roles',
        'casts' => ['is_system' => 'bool'],
        'relations' => 'mt:Permission:permissions:role_permissions:role_id:permission_id | h:EmployeeRole:employeeRoles:role_id',
    ],
    'Permission' => [
        'table' => 'permissions',
        'relations' => 'mt:Role:roles:role_permissions:permission_id:role_id',
    ],
    'RolePermission' => [
        'table' => 'role_permissions',
        'casts' => ['scope' => 'PermissionScope'],
        'relations' => 'b:Role:role:role_id | b:Permission:permission:permission_id',
    ],
    'EmployeeRole' => [
        'table' => 'employee_roles',
        'relations' => 'b:Employee:employee:employee_id | b:Role:role:role_id | b:Department:department:department_id',
    ],
    'EmployeePermissionOverride' => [
        'table' => 'employee_permission_overrides',
        'casts' => ['scope' => 'PermissionScope'],
        'relations' => 'b:Employee:employee:employee_id | b:Permission:permission:permission_id',
    ],
    'WorkSchedule' => [
        'table' => 'work_schedules',
        'casts' => ['is_active' => 'bool', 'type' => 'ScheduleType', 'allow_overtime' => 'bool'],
        'relations' => 'b:Company:company:company_id | h:ScheduleShift:shifts:work_schedule_id | h:Employee:employees:work_schedule_id',
    ],
    'ScheduleAssignment' => [
        'table' => 'schedule_assignments',
        'casts' => ['effective_from' => 'date', 'effective_to' => 'date'],
        'relations' => 'b:Employee:employee:employee_id | b:WorkSchedule:workSchedule:work_schedule_id',
    ],
    'ScheduleShift' => [
        'table' => 'schedule_shifts',
        'relations' => 'b:WorkSchedule:workSchedule:work_schedule_id',
    ],
    'Holiday' => [
        'table' => 'holidays',
        'casts' => ['date' => 'date', 'is_day_off' => 'bool'],
        'relations' => 'b:Company:company:company_id',
    ],
    'LeaveRequest' => [
        'table' => 'leave_requests',
        'casts' => ['type' => 'LeaveType', 'status' => 'LeaveStatus', 'from_date' => 'date', 'to_date' => 'date', 'decided_at' => 'datetime'],
        'relations' => 'b:Employee:employee:employee_id | b:Employee:approver:approver_id',
    ],
    'AttendanceRecord' => [
        'table' => 'attendance_records',
        'casts' => ['status' => 'AttendanceStatus', 'source' => 'AttendanceSource', 'date' => 'date', 'clock_in_at' => 'datetime', 'clock_out_at' => 'datetime', 'schedule_snapshot' => 'array'],
        'relations' => 'b:Employee:employee:employee_id | h:AttendanceBreak:breaks:attendance_record_id',
    ],
    'AttendanceBreak' => [
        'table' => 'attendance_breaks',
        'casts' => ['started_at' => 'datetime', 'ended_at' => 'datetime'],
        'relations' => 'b:AttendanceRecord:attendanceRecord:attendance_record_id',
    ],
    'AttendanceCorrection' => [
        'table' => 'attendance_corrections',
        'casts' => ['status' => 'CorrectionStatus', 'date' => 'date', 'old_value' => 'datetime', 'new_value' => 'datetime', 'decided_at' => 'datetime'],
        'relations' => 'b:Employee:employee:employee_id | b:Employee:requestedBy:requested_by | b:Employee:decidedBy:decided_by',
    ],
    'AttendanceAnomaly' => [
        'table' => 'attendance_anomalies',
        'casts' => ['type' => 'AnomalyType', 'status' => 'AnomalyStatus', 'date' => 'date', 'resolved_at' => 'datetime'],
        'relations' => 'b:Employee:employee:employee_id | b:Employee:resolvedBy:resolved_by',
    ],
    'BiometricDevice' => [
        'table' => 'biometric_devices',
        'casts' => ['direction' => 'DeviceDirection', 'status' => 'DeviceStatus', 'last_seen_at' => 'datetime', 'capabilities' => 'array'],
        'relations' => 'b:Company:company:company_id | h:BiometricEvent:events:device_id | h:BiometricEnrollment:enrollments:device_id',
    ],
    'BiometricProfile' => [
        'table' => 'biometric_profiles',
        'casts' => ['status' => 'BiometricProfileStatus', 'enrolled_at' => 'datetime', 'expires_at' => 'datetime'],
        'relations' => 'b:Employee:employee:employee_id | b:Employee:enrolledBy:enrolled_by',
    ],
    'BiometricEvent' => [
        'table' => 'biometric_events',
        'casts' => ['occurred_at' => 'datetime', 'processed_at' => 'datetime', 'processing_status' => 'BiometricEventStatus', 'liveness_passed' => 'bool', 'liveness_required' => 'bool', 'raw_payload' => 'array', 'confidence' => 'float'],
        'relations' => 'b:BiometricDevice:device:device_id | b:Employee:employee:employee_id',
    ],
    'BiometricEnrollment' => [
        'table' => 'biometric_enrollments',
        'casts' => ['status' => 'EnrollmentStatus'],
        'relations' => 'b:Employee:employee:employee_id | b:BiometricDevice:device:device_id',
    ],
    'Task' => [
        'table' => 'tasks',
        'casts' => ['type' => 'TaskType', 'priority' => 'TaskPriority', 'status' => 'TaskStatus', 'deadline' => 'datetime', 'completed_at' => 'datetime', 'meta' => 'array'],
        'softDeletes' => true,
        'relations' => 'b:Task:parent:parent_id | h:Task:children:parent_id | b:Company:company:company_id | b:Department:department:department_id | b:Employee:author:author_id | h:TaskAssignment:assignments:task_id | h:TaskWatcher:watchers:task_id | h:TaskComment:comments:task_id | h:TaskAttachment:attachments:task_id | h:TaskStatusHistory:statusHistory:task_id',
    ],
    'TaskAssignment' => [
        'table' => 'task_assignments',
        'casts' => ['assignee_type' => 'AssigneeType', 'role' => 'TaskAssignmentRole', 'assignment_status' => 'TaskAssignmentStatus', 'acknowledged_at' => 'datetime'],
        'relations' => 'b:Task:task:task_id | b:Employee:employee:employee_id | b:Department:department:department_id',
    ],
    'TaskWatcher' => [
        'table' => 'task_watchers',
        'relations' => 'b:Task:task:task_id | b:Employee:employee:employee_id',
    ],
    'TaskComment' => [
        'table' => 'task_comments',
        'relations' => 'b:Task:task:task_id | b:Employee:employee:employee_id',
    ],
    'TaskAttachment' => [
        'table' => 'task_attachments',
        'relations' => 'b:Task:task:task_id | b:Employee:employee:employee_id',
    ],
    'TaskStatusHistory' => [
        'table' => 'task_status_history',
        'noTimestamps' => 'created_at',
        'relations' => 'b:Task:task:task_id | b:Employee:employee:employee_id',
    ],
    'TaskDelegation' => [
        'table' => 'task_delegations',
        'relations' => 'b:Task:task:task_id | b:Employee:fromEmployee:from_employee_id | b:Employee:toEmployee:to_employee_id',
    ],
    'TaskDependency' => [
        'table' => 'task_dependencies',
        'relations' => 'b:Task:task:task_id | b:Task:dependsOn:depends_on_task_id',
    ],
    'TaskTimeLog' => [
        'table' => 'task_time_logs',
        'casts' => ['started_at' => 'datetime', 'stopped_at' => 'datetime'],
        'relations' => 'b:Task:task:task_id | b:Employee:employee:employee_id',
    ],
    'TaskResult' => [
        'table' => 'task_results',
        'relations' => 'b:Task:task:task_id | b:Employee:submittedBy:submitted_by',
    ],
    'DocumentCategory' => [
        'table' => 'document_categories',
        'casts' => ['is_active' => 'bool'],
        'relations' => 'h:Document:documents:category_id',
    ],
    'Document' => [
        'table' => 'documents',
        'casts' => ['status' => 'DocumentStatus', 'type' => 'DocumentType', 'priority' => 'DocumentPriority', 'confidentiality' => 'Confidentiality', 'due_at' => 'datetime'],
        'softDeletes' => true,
        'relations' => 'b:Company:company:company_id | b:DocumentCategory:category:category_id | b:DocumentTemplate:template:template_id | b:Employee:author:author_id | b:Department:department:department_id | h:DocumentVersion:versions:document_id | h:DocumentApproval:approvals:document_id | h:DocumentSignatureRequest:signatureRequests:document_id',
    ],
    'DocumentTemplate' => [
        'table' => 'document_templates',
        'casts' => ['is_active' => 'bool', 'variables' => 'array'],
        'relations' => 'b:Company:company:company_id | b:DocumentCategory:category:category_id | b:DocumentWorkflow:workflow:workflow_id',
    ],
    'DocumentVersion' => [
        'table' => 'document_versions',
        'casts' => ['immutable' => 'bool', 'sha256' => 'string'],
        'relations' => 'b:Document:document:document_id | b:Employee:createdBy:created_by',
    ],
    'DocumentWorkflow' => [
        'table' => 'document_workflows',
        'casts' => ['is_active' => 'bool', 'applicable_categories' => 'array'],
        'relations' => 'b:Company:company:company_id | h:DocumentWorkflowStep:steps:workflow_id',
    ],
    'DocumentWorkflowStep' => [
        'table' => 'document_workflow_steps',
        'casts' => ['actor_type' => 'WorkflowActorType', 'required_action' => 'WorkflowAction', 'is_signature_step' => 'bool', 'is_terminal' => 'bool'],
        'relations' => 'b:DocumentWorkflow:workflow:workflow_id | b:Role:role:role_id | b:Department:department:department_id',
    ],
    'DocumentWorkflowState' => [
        'table' => 'document_workflows_pivot',
        'casts' => ['state' => 'WorkflowState', 'current_step' => 'int'],
        'relations' => 'b:Document:document:document_id | b:DocumentWorkflow:workflow:workflow_id',
    ],
    'DocumentApproval' => [
        'table' => 'document_approvals',
        'casts' => ['status' => 'ApprovalStatus', 'decided_at' => 'datetime'],
        'relations' => 'b:Document:document:document_id | b:DocumentWorkflowStep:workflowStep:workflow_step_id | b:Employee:approver:approver_id',
    ],
    'DocumentSignatureRequest' => [
        'table' => 'document_signature_requests',
        'casts' => ['driver' => 'SignatureDriver', 'status' => 'SignatureRequestStatus', 'requires_otp' => 'bool', 'signers' => 'array'],
        'relations' => 'b:Document:document:document_id | b:DocumentVersion:documentVersion:document_version_id | b:Employee:requestedBy:requested_by | b:Employee:signer:signer_id | h:DocumentSignature:signatures:signature_request_id',
    ],
    'DocumentSignature' => [
        'table' => 'document_signatures',
        'casts' => ['status' => 'SignatureStatus', 'signature_type' => 'SignatureDriver', 'signed_at' => 'datetime', 'revoked_at' => 'datetime', 'meta' => 'array'],
        'relations' => 'b:Document:document:document_id | b:DocumentVersion:documentVersion:document_version_id | b:DocumentSignatureRequest:signatureRequest:signature_request_id | b:Employee:signer:signer_id | b:Employee:revokedBy:revoked_by',
    ],
    'QrCode' => [
        'table' => 'qr_codes',
        'casts' => ['kind' => 'QrKind', 'status' => 'QrStatus', 'expires_at' => 'datetime', 'revoked_at' => 'datetime', 'generation' => 'int'],
        'relations' => 'b:Employee:employee:employee_id',
    ],
    'QrToken' => [
        'table' => 'qr_tokens',
        'casts' => ['expires_at' => 'datetime', 'used_at' => 'datetime', 'revoked_at' => 'datetime', 'one_time' => 'bool', 'meta' => 'array'],
        'relations' => 'b:Employee:createdBy:created_by',
    ],
    'AuditLog' => [
        'table' => 'audit_logs',
        'noTimestamps' => 'created_at',
        'casts' => ['old_value' => 'array', 'new_value' => 'array', 'created_at' => 'datetime'],
        'relations' => 'b:User:user:user_id',
    ],
    'Setting' => [
        'table' => 'settings',
        'relations' => '',
    ],
    'AppNotification' => [
        'table' => 'attendance_notifications',
        'casts' => ['read_at' => 'datetime'],
        'relations' => 'b:Employee:recipient:recipient_id',
    ],
    'TelegramLink' => [
        'table' => 'telegram_links',
        'casts' => ['verified_at' => 'datetime', 'notifications_enabled' => 'bool'],
        'relations' => 'b:Employee:employee:employee_id',
    ],
    'AiConversation' => [
        'table' => 'ai_conversations',
        'casts' => ['grounded' => 'bool', 'query_used' => 'array'],
        'relations' => 'b:Employee:employee:employee_id',
    ],
];

$modelsNs = 'App\\Models';

foreach ($models as $name => $spec) {
    $props = [];
    $methods = [];

    $props[] = "    protected \$table = '{$spec['table']}';";
    $props[] = "    protected \$guarded = []; // Octane-safe: строгие fillable добавим по мере BR-проверок";

    if (!empty($spec['softDeletes'])) {
        $props[] = "    use \\Illuminate\\Database\\Eloquent\\SoftDeletes;";
    }
    if (!empty($spec['noTimestamps'])) {
        $props[] = "    public \$timestamps = false;";
    }
    if (!empty($spec['casts'])) {
        $props[] = "    protected function casts(): array\n    {\n        return [\n" . castLines($spec['casts']) . "\n        ];\n    }";
    }

    foreach (explode(' | ', $spec['relations'] ?? '') as $rel) {
        if ($rel === '') {
            continue;
        }
        $methods[] = relationMethod($rel);
    }

    $php = "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$modelsNs};\n\nuse Illuminate\\Database\\Eloquent\\Model;\nuse Illuminate\\Database\\Eloquent\\Relations\\BelongsTo;\nuse Illuminate\\Database\\Eloquent\\Relations\\BelongsToMany;\nuse Illuminate\\Database\\Eloquent\\Relations\\HasMany;\nuse Illuminate\\Database\\Eloquent\\Relations\\HasOne;\n\n";
    $php .= "class {$name} extends Model\n{\n";
    $php .= implode("\n", $props) . "\n";
    if ($methods) {
        $php .= "\n" . implode("", $methods);
    }
    $php .= "}\n";

    file_put_contents($modelDir . '/' . $name . '.php', $php);
}

function castLines(array $casts): string
{
    $lines = [];
    foreach ($casts as $field => $cast) {
        if (in_array($cast, ['date', 'datetime', 'bool', 'array', 'string', 'int', 'float'], true)) {
            $lines[] = "            '{$field}' => '{$cast}'";
        } else {
            $lines[] = "            '{$field}' => \\App\\Shared\\Enums\\{$cast}::class";
        }
    }
    return implode(",\n", $lines);
}

function relationMethod(string $rel): string
{
    $parts = explode(':', $rel);
    $kind = array_shift($parts);

    if ($kind === 'mt') {
        [$class, $method, $pivot, $fk, $rk] = $parts;
        return "    public function {$method}(): BelongsToMany\n    {\n        return \$this->belongsToMany({$class}::class, '{$pivot}', '{$fk}', '{$rk}');\n    }\n\n";
    }

    [$class, $method, $fk] = $parts;
    $cls = $class === 'Company' ? 'self::class' : "{$class}::class";

    $ret = match ($kind) {
        'b' => 'belongsTo',
        'h' => 'hasMany',
        'o' => 'hasOne',
    };
    $type = ['b' => 'BelongsTo', 'h' => 'HasMany', 'o' => 'HasOne'][$kind];

    return "    public function {$method}(): {$type}\n    {\n        return \$this->{$ret}({$cls}, '{$fk}');\n    }\n\n";
}

echo 'Enums written: ' . count($enums) . PHP_EOL;
echo 'Models written: ' . count($models) . PHP_EOL;
echo "Done\n";
