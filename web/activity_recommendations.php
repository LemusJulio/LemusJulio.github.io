<?php
declare(strict_types=1);

function buildActivityRecommendations(
    array $activities,
    array $archivedActivities,
    string $creatorName,
    ?DateTimeImmutable $today = null
): array {
    $today ??= new DateTimeImmutable("today");
    $todayWeekday = (int)$today->format("N");
    $history = array_merge(
        array_values(array_filter($activities, static fn(mixed $activity): bool => is_array($activity))),
        array_values(array_filter($archivedActivities, static fn(mixed $activity): bool => is_array($activity)))
    );
    $activeKeys = [];
    $groups = [];

    foreach ($activities as $activity) {
        if (!is_array($activity)
            || !in_array(($activity["status"] ?? ""), ["pendiente", "en_progreso", "pausada"], true)) {
            continue;
        }
        $titleKey = mb_strtolower(trim((string)($activity["title"] ?? "")));
        $projectKey = mb_strtolower(trim((string)($activity["project"] ?? "")));
        if ($titleKey !== "") {
            $activeKeys[$titleKey . "|" . $projectKey] = true;
        }
    }

    foreach ($history as $activity) {
        $title = trim((string)($activity["title"] ?? ""));
        if ($title === "" || trim((string)($activity["assignedBy"] ?? "")) !== trim($creatorName)) {
            continue;
        }

        $project = trim((string)($activity["project"] ?? ""));
        $titleKey = mb_strtolower($title);
        $projectKey = mb_strtolower($project);
        $key = $titleKey . "|" . $projectKey;
        if (isset($activeKeys[$key])) {
            continue;
        }

        if (!isset($groups[$key])) {
            $groups[$key] = [
                "title" => $title,
                "description" => trim((string)($activity["description"] ?? "")),
                "project" => $project,
                "count" => 0,
                "weekdays" => [],
                "assignees" => [],
                "priorities" => [],
                "seenGroups" => [],
                "seenWeekdays" => [],
                "latestTimestamp" => 0
            ];
        }

        $group = &$groups[$key];
        $groupId = (string)($activity["groupId"] ?? $activity["id"] ?? "");
        if ($groupId === "" || !isset($group["seenGroups"][$groupId])) {
            $group["count"]++;
            if ($groupId !== "") {
                $group["seenGroups"][$groupId] = true;
            }
        }

        $assignedAt = trim((string)($activity["assignedAt"] ?? $activity["startedAt"] ?? $activity["endedAt"] ?? ""));
        $timestamp = $assignedAt !== "" ? strtotime($assignedAt) : false;
        if ($timestamp !== false) {
            $weekday = (int)date("N", $timestamp);
            $weekdayGroupKey = $groupId !== "" ? $groupId : (string)($activity["id"] ?? "");
            if ($weekdayGroupKey === "" || !isset($group["seenWeekdays"][$weekdayGroupKey])) {
                $group["weekdays"][$weekday] = ($group["weekdays"][$weekday] ?? 0) + 1;
                if ($weekdayGroupKey !== "") {
                    $group["seenWeekdays"][$weekdayGroupKey] = true;
                }
            }
            if ($timestamp >= $group["latestTimestamp"]) {
                $group["latestTimestamp"] = $timestamp;
                $description = trim((string)($activity["description"] ?? ""));
                if ($description !== "") {
                    $group["description"] = $description;
                }
            }
        }

        $assignee = trim((string)($activity["assignedTo"] ?? ""));
        if ($assignee !== "") {
            $group["assignees"][$assignee] = ($group["assignees"][$assignee] ?? 0) + 1;
        }
        $priority = (string)($activity["priority"] ?? "media");
        if (in_array($priority, ["baja", "media", "alta", "urgente"], true)) {
            $group["priorities"][$priority] = ($group["priorities"][$priority] ?? 0) + 1;
        }
        unset($group);
    }

    $weekdayNames = [1 => "lunes", 2 => "martes", 3 => "miércoles", 4 => "jueves", 5 => "viernes", 6 => "sábado", 7 => "domingo"];
    $recommendations = [];
    $recentThreshold = $today->modify("-30 days")->getTimestamp();
    foreach ($groups as $group) {
        if ($group["count"] < 2) {
            continue;
        }

        $usualWeekday = null;
        $weekdayCount = 0;
        foreach ($group["weekdays"] as $weekday => $count) {
            if ($count > $weekdayCount) {
                $usualWeekday = (int)$weekday;
                $weekdayCount = $count;
            }
        }

        $assignee = "";
        if ($group["assignees"]) {
            arsort($group["assignees"]);
            $assignee = (string)array_key_first($group["assignees"]);
        }
        $priority = "media";
        if ($group["priorities"]) {
            arsort($group["priorities"]);
            $priority = (string)array_key_first($group["priorities"]);
        }

        $reason = "La has creado {$group["count"]} veces";
        if ($usualWeekday !== null && $weekdayCount >= 2) {
            $reason .= " · suele repetirse los " . $weekdayNames[$usualWeekday];
        }
        if ($assignee !== "") {
            $reason .= " · asignada con más frecuencia a {$assignee}";
        }

        $recommendations[] = [
            "title" => $group["title"],
            "description" => $group["description"],
            "project" => $group["project"],
            "assignee" => $assignee,
            "priority" => $priority,
            "reason" => $reason,
            "score" => ($group["count"] * 3)
                + ($group["weekdays"][$todayWeekday] ?? 0) * 2
                + ($group["latestTimestamp"] >= $recentThreshold ? 1 : 0),
            "count" => $group["count"]
        ];
    }

    usort($recommendations, static function (array $first, array $second): int {
        return ($second["score"] <=> $first["score"]) ?: ($second["count"] <=> $first["count"]);
    });
    return array_slice($recommendations, 0, 4);
}
