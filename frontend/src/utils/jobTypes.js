// List of all available job types for diagnostics filtering
// Add new job types here as needed
export const JOB_TYPES = [
  'App\\Jobs\\SendMailJob',
  'App\\Jobs\\SendWebhookJob',
  'App\\Jobs\\SendEventTriggerJob',
  'App\\Jobs\\WorkerHeartbeatJob',
  'App\\Jobs\\CheckScheduledTasksJob',
  'App\\Jobs\\CleanUpJobLogsJob',
  'App\\Jobs\\CleanUpScheduledTaskExecutionsJob',
  'App\\Jobs\\ExecuteScheduledTaskJob',
  // Add more job types as needed
];
