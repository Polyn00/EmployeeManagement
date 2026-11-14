<?php

function audit_record(
    PDO $pdo,
    string $action,
    string $entityType,
    ?int $entityId,
    array $details = [],
    ?int $actorUserId = null
): void {
    if (!preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $action)
        || !preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $entityType)
    ) {
        throw new InvalidArgumentException('Invalid audit action or entity type.');
    }

    foreach ($details as $key => $value) {
        if (!is_string($key) || preg_match('/password|secret|token|session|credential/i', $key)) {
            throw new InvalidArgumentException('Sensitive data cannot be written to audit details.');
        }
        if (!is_scalar($value) && $value !== null) {
            throw new InvalidArgumentException('Audit detail values must be scalar.');
        }
    }

    $encodedDetails = $details === []
        ? null
        : json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $statement = $pdo->prepare(
        'INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, details)
         VALUES (:actor_user_id, :action, :entity_type, :entity_id, :details)'
    );
    $statement->execute([
        ':actor_user_id' => $actorUserId,
        ':action' => $action,
        ':entity_type' => $entityType,
        ':entity_id' => $entityId,
        ':details' => $encodedDetails,
    ]);
}
