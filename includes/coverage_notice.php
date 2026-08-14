<?php
/**
 * Coverage notices for the approval queues.
 *
 * All three stages ask the same question before signing off - does approving
 * this leave the applicant's department short-handed - so the wording lives here
 * rather than being written out three times with three different phrasings.
 *
 * Nothing here blocks an approval. Sick leave does not wait for a rota to be
 * convenient, and HR keeps the final say; the screen's job is to make sure the
 * approver knows what they are about to do.
 */
require_once __DIR__ . '/../helpers/LeaveCapacity.php';

/**
 * Short "Mon 17 Aug" list of the days in a set of capacity warnings.
 */
function coverage_dates(array $warnings, int $limit = 6): string {
    $labels = [];
    foreach (array_slice($warnings, 0, $limit) as $warning) {
        $labels[] = date('D j M', strtotime($warning['date']));
    }
    $extra = count($warnings) - count($labels);
    return implode(', ', $labels) . ($extra > 0 ? sprintf(' and %d more', $extra) : '');
}

/**
 * Row-level flag for the queue table, so an approver can see which requests
 * need a closer look without opening every one.
 */
function coverage_badge(array $impact): string {
    if ($impact['limit'] === null) {
        return '';
    }
    if (!empty($impact['tips_over'])) {
        return '<span class="badge badge-danger"><i class="ti-alert"></i> Leaves team short</span>';
    }
    if (!empty(LeaveCapacity::breachesOnly($impact['warnings']))) {
        return '<span class="badge badge-danger"><i class="ti-alert"></i> Already over limit</span>';
    }
    if (!empty($impact['warnings'])) {
        return '<span class="badge badge-warning text-dark"><i class="ti-info-alt"></i> At limit</span>';
    }
    return '';
}

/**
 * The notice shown inside the review modal, above the remarks box.
 */
function coverage_notice(array $impact): string {
    if ($impact['limit'] === null) {
        // No threshold configured for this department, so there is nothing
        // meaningful to say about cover.
        return '';
    }

    $dept      = htmlspecialchars($impact['department_name'] ?? 'the department');
    $limit     = (int)$impact['limit'];
    $headcount = (int)$impact['headcount'];
    $breaches  = LeaveCapacity::breachesOnly($impact['warnings']);

    if (!empty($impact['tips_over'])) {
        return '<div class="alert alert-danger ri-coverage">'
            . '<strong><i class="ti-alert"></i> Approving this leaves ' . $dept . ' short of cover.</strong>'
            . '<div>More than ' . $limit . ' of ' . $headcount . ' would be away on: '
            . htmlspecialchars(coverage_dates($impact['tips_over'])) . '.</div>'
            . '<div class="mt-1 text-muted">Check the team calendar before you decide. You can still approve '
            . 'if the absence cannot move.</div>'
            . '</div>';
    }

    if (!empty($breaches)) {
        return '<div class="alert alert-warning ri-coverage">'
            . '<strong><i class="ti-alert"></i> ' . $dept . ' is already over its limit.</strong>'
            . '<div>More than ' . $limit . ' of ' . $headcount . ' are away on '
            . htmlspecialchars(coverage_dates($breaches)) . ', with or without this request.</div>'
            . '</div>';
    }

    if (!empty($impact['warnings'])) {
        return '<div class="alert alert-warning ri-coverage">'
            . '<strong><i class="ti-info-alt"></i> This takes ' . $dept . ' to its limit.</strong>'
            . '<div>' . $limit . ' of ' . $headcount . ' away on '
            . htmlspecialchars(coverage_dates($impact['warnings']))
            . ' - allowed, but no cover left over.</div>'
            . '</div>';
    }

    return '<div class="alert alert-success ri-coverage mb-3">'
        . '<i class="ti-check"></i> Cover holds: ' . $dept . ' stays within its limit of '
        . $limit . ' away at a time.'
        . '</div>';
}
