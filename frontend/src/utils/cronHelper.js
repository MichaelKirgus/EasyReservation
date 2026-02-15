/**
 * Cron expression validator and helper functions
 */

// Valid cron expression pattern (5 fields: minute hour day month weekday)
const CRON_PATTERN = /^(\*|[0-9*,\/\-]+)\s+(\*|[0-9*,\/\-]+)\s+(\*|[0-9*,\/\-]+)\s+(\*|[0-9*,\/\-]+)\s+(\*|[0-9*,\/\-]+)$/;

/**
 * Check if a cron expression is valid
 * @param {string} expression - The cron expression to validate
 * @returns {boolean} True if the expression is valid, false otherwise
 */
export function isValidCron(expression) {
  if (!expression || typeof expression !== 'string') return false;
  
  // Trim and check pattern
  const trimmed = expression.trim();
  if (trimmed.length === 0) return false;
  
  return CRON_PATTERN.test(trimmed);
}

/**
 * Get a list of common cron expression examples
 * @returns {Array} Array of example objects with label and value
 */
export function getCronExamples() {
  return [
    { label: 'Jede Minute', value: '* * * * *' },
    { label: 'Jede Stunde (zur vollen Stunde)', value: '0 * * * *' },
    { label: 'Alle 30 Minuten', value: '*/30 * * * *' },
    { label: 'Täglich um 08:00 Uhr', value: '0 8 * * *' },
    { label: 'Täglich um 12:00 und 18:00 Uhr', value: '0 8,12,18 * * *' },
    { label: 'Wöchentlich am Montag um 14:30', value: '30 14 * * 1' },
    { label: 'Monatlich am 1. um 00:00', value: '0 0 1 * *' },
    { label: 'Jährlich am 1. Januar um 09:00', value: '0 9 1 1 *' },
    { label: 'An jedem Werktag um 09:00', value: '0 9 * * 1-5' },
    { label: 'Alle 2 Stunden', value: '0 */2 * * *' },
  ];
}

/**
 * Get a human-readable description of a cron expression
 * @param {string} expression - The cron expression
 * @returns {string} A human-readable description
 */
export function getCronDescription(expression) {
  if (!isValidCron(expression)) return 'Ungültiger Cron-Ausdruck';
  
  const parts = expression.trim().split(/\s+/);
  if (parts.length !== 5) return 'Ungültiges Format (muss 5 Felder haben)';
  
  const [minute, hour, day, month, weekday] = parts;
  
  // Build description based on cron fields
  let desc = '';
  
  // Minute field
  if (minute === '*') {
    desc += 'Jede Minute';
  } else if (minute.startsWith('*/')) {
    const interval = minute.substring(2);
    desc += `Alle ${interval} Minuten`;
  } else if (minute.includes(',')) {
    desc += `In den Minuten: ${minute}`;
  } else {
    desc += `Um Minute ${minute}`;
  }
  
  // Hour field
  if (hour === '*') {
    desc += ' jede Stunde';
  } else if (hour.startsWith('*/')) {
    const interval = hour.substring(2);
    desc += ` alle ${interval} Stunden`;
  } else if (hour.includes(',')) {
    desc += ` um ${hour.replace(',', ', ')}`;
  } else {
    desc += ` um ${hour}:00`;
  }
  
  // Day field
  if (day !== '*') {
    if (day === '1') {
      desc += ' am 1. Tag des Monats';
    } else if (day.includes(',')) {
      desc += ` an den Tagen: ${day}`;
    } else {
      desc += ` am ${day}. Tag des Monats`;
    }
  }
  
  // Month field
  const months = ['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];
  if (month !== '*') {
    if (month.includes(',')) {
      const monthNames = month.split(',').map(m => months[parseInt(m) - 1]).join(', ');
      desc += ` im ${monthNames}`;
    } else {
      desc += ` im ${months[parseInt(month) - 1]}`;
    }
  }
  
  // Weekday field
  const weekdays = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
  if (weekday !== '*') {
    if (weekday.includes(',')) {
      const dayNames = weekday.split(',').map(d => weekdays[parseInt(d)]).join(', ');
      desc += ` an den Tagen: ${dayNames}`;
    } else if (weekday === '0' || weekday === '7') {
      desc += ' am Sonntag';
    } else if (weekday >= 1 && weekday <= 5) {
      desc += ` an den Werktagen (${weekdays[weekday]})`;
    } else if (weekday === '1-5') {
      desc += ' an allen Werktagen (Mo-Fr)';
    }
  }
  
  return desc.trim();
}

/**
 * Parse a cron expression and get its parts
 * @param {string} expression - The cron expression
 * @returns {object|null} Object with parsed parts or null if invalid
 */
export function parseCron(expression) {
  if (!isValidCron(expression)) return null;
  
  const parts = expression.trim().split(/\s+/);
  return {
    minute: parts[0],
    hour: parts[1],
    day: parts[2],
    month: parts[3],
    weekday: parts[4],
  };
}
