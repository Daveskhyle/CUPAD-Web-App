<?php
include_once 'json_helpers.php'; // Include the new helper functions

function updateUserActivity($user_id) {
    $activity_file = __DIR__ . '/../user_activity.json';
    $activities = readJsonFile($activity_file); // Use helper function
    
    $activities[$user_id] = time();
    writeJsonFile($activity_file, $activities); // Use helper function
}

function getOnlineUsers($timeout_minutes = 5) {
    $activity_file = __DIR__ . '/../user_activity.json';
    $activities = readJsonFile($activity_file); // Use helper function
    
    $cutoff_time = time() - ($timeout_minutes * 60);
    
    return array_filter($activities, function($last_activity) use ($cutoff_time) {
        return $last_activity >= $cutoff_time;
    });
}

function isUserOnline($user_id, $timeout_minutes = 5) {
    $online_users = getOnlineUsers($timeout_minutes);
    return isset($online_users[$user_id]);
}

function getOnlineUsersCount($timeout_minutes = 5) {
    return count(getOnlineUsers($timeout_minutes));
}
?>