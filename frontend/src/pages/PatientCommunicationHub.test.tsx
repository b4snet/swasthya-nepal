import { describe, expect, it } from 'vitest';

// ── Communication category definitions (extracted from PatientCommunicationHub) ──
interface CommCategory {
  id: string;
  label: string;
  Icon: any;
  color: string;
  bgColor: string;
  types: string[];
  description: string;
}

const COMM_CATEGORIES: CommCategory[] = [
  {
    id: 'patient',
    label: 'Patient Messages',
    Icon: null,
    color: '#3b82f6',
    bgColor: '#eff6ff',
    types: ['patient_message', 'patient_question', 'patient_response'],
    description: 'Messages from or to the patient',
  },
  {
    id: 'care_team',
    label: 'Care Coordination',
    Icon: null,
    color: '#8b5cf6',
    bgColor: '#f5f3ff',
    types: ['handoff', 'clarification', 'coordination', 'consultation'],
    description: 'Internal care team communication',
  },
  {
    id: 'notification',
    label: 'Notifications',
    Icon: null,
    color: '#64748b',
    bgColor: '#f8fafc',
    types: ['notification', 'info', 'update'],
    description: 'System-generated notifications',
  },
  {
    id: 'reminder',
    label: 'Reminders',
    Icon: null,
    color: '#f59e0b',
    bgColor: '#fffbeb',
    types: ['reminder', 'appointment_reminder', 'medication_reminder'],
    description: 'Time-based reminders',
  },
  {
    id: 'followup',
    label: 'Follow-ups',
    Icon: null,
    color: '#ec4899',
    bgColor: '#fdf2f8',
    types: ['followup', 'follow_up', 'follow_up_reminder'],
    description: 'Workflow-driven follow-ups',
  },
  {
    id: 'alert',
    label: 'Alerts',
    Icon: null,
    color: '#ef4444',
    bgColor: '#fef2f2',
    types: ['alert', 'urgent', 'critical'],
    description: 'Action-required notifications',
  },
];

// ── Message type taxonomy ──
interface CommMessage {
  id: string;
  subject: string;
  body: string;
  type: string;
  category: string;
  channel: string;
  status: string;
  readAt: string | null;
  createdAt: string;
  patientId?: string;
}

// ── Message categorization logic (mirrors component) ──
function categorizeMessages(messages: CommMessage[]): Record<string, CommMessage[]> {
  const result: Record<string, CommMessage[]> = {};
  for (const cat of COMM_CATEGORIES) {
    result[cat.id] = messages.filter(msg =>
      cat.types.includes(msg.type) ||
      cat.types.includes(msg.category) ||
      (cat.id === 'patient' && msg.type === 'patient_message')
    );
  }
  return result;
}

// ── Unread count logic ──
function countUnread(categorized: Record<string, CommMessage[]>): Record<string, number> {
  const counts: Record<string, number> = {};
  for (const [catId, msgs] of Object.entries(categorized)) {
    counts[catId] = msgs.filter(m => !m.readAt).length;
  }
  return counts;
}

describe('PatientCommunicationHub communication categories', () => {
  it('has exactly 6 categories', () => {
    expect(COMM_CATEGORIES).toHaveLength(6);
  });

  it('has patient category with patient message types', () => {
    const patient = COMM_CATEGORIES.find(c => c.id === 'patient');
    expect(patient).toBeDefined();
    expect(patient!.types).toContain('patient_message');
    expect(patient!.types).toContain('patient_question');
    expect(patient!.types).toContain('patient_response');
  });

  it('has care_team category with coordination types', () => {
    const careTeam = COMM_CATEGORIES.find(c => c.id === 'care_team');
    expect(careTeam).toBeDefined();
    expect(careTeam!.types).toContain('handoff');
    expect(careTeam!.types).toContain('coordination');
    expect(careTeam!.types).toContain('consultation');
  });

  it('has notification category with system types', () => {
    const notif = COMM_CATEGORIES.find(c => c.id === 'notification');
    expect(notif).toBeDefined();
    expect(notif!.types).toContain('notification');
    expect(notif!.types).toContain('info');
  });

  it('has reminder category with time-based types', () => {
    const reminder = COMM_CATEGORIES.find(c => c.id === 'reminder');
    expect(reminder).toBeDefined();
    expect(reminder!.types).toContain('reminder');
    expect(reminder!.types).toContain('appointment_reminder');
    expect(reminder!.types).toContain('medication_reminder');
  });

  it('has followup category with workflow types', () => {
    const followup = COMM_CATEGORIES.find(c => c.id === 'followup');
    expect(followup).toBeDefined();
    expect(followup!.types).toContain('followup');
    expect(followup!.types).toContain('follow_up');
  });

  it('has alert category with urgent types', () => {
    const alert = COMM_CATEGORIES.find(c => c.id === 'alert');
    expect(alert).toBeDefined();
    expect(alert!.types).toContain('alert');
    expect(alert!.types).toContain('urgent');
    expect(alert!.types).toContain('critical');
  });
});

describe('PatientCommunicationHub message categorization', () => {
  const testMessages: CommMessage[] = [
    { id: '1', subject: 'Patient question', body: 'When is my appointment?', type: 'patient_question', category: 'patient', channel: 'in_app', status: 'delivered', readAt: null, createdAt: '2024-01-01T00:00:00Z' },
    { id: '2', subject: 'Handoff note', body: 'Patient transferred to ICU', type: 'handoff', category: 'care_team', channel: 'in_app', status: 'delivered', readAt: null, createdAt: '2024-01-01T01:00:00Z' },
    { id: '3', subject: 'System notification', body: 'New lab results available', type: 'notification', category: 'system', channel: 'in_app', status: 'delivered', readAt: '2024-01-01T02:00:00Z', createdAt: '2024-01-01T01:00:00Z' },
    { id: '4', subject: 'Appointment reminder', body: 'Your appointment is tomorrow', type: 'appointment_reminder', category: 'reminder', channel: 'sms', status: 'sent', readAt: null, createdAt: '2024-01-01T02:00:00Z' },
    { id: '5', subject: 'Follow-up needed', body: 'Please schedule follow-up', type: 'follow_up', category: 'followup', channel: 'email', status: 'delivered', readAt: null, createdAt: '2024-01-01T03:00:00Z' },
    { id: '6', subject: 'Critical alert', body: 'Abnormal lab result', type: 'critical', category: 'alert', channel: 'push', status: 'delivered', readAt: null, createdAt: '2024-01-01T04:00:00Z' },
  ];

  it('categorizes messages correctly', () => {
    const categorized = categorizeMessages(testMessages);
    expect(categorized.patient).toHaveLength(1);
    expect(categorized.patient[0].id).toBe('1');
    expect(categorized.care_team).toHaveLength(1);
    expect(categorized.care_team[0].id).toBe('2');
    expect(categorized.notification).toHaveLength(1);
    expect(categorized.notification[0].id).toBe('3');
    expect(categorized.reminder).toHaveLength(1);
    expect(categorized.reminder[0].id).toBe('4');
    expect(categorized.followup).toHaveLength(1);
    expect(categorized.followup[0].id).toBe('5');
    expect(categorized.alert).toHaveLength(1);
    expect(categorized.alert[0].id).toBe('6');
  });

  it('counts unread messages correctly', () => {
    const categorized = categorizeMessages(testMessages);
    const unread = countUnread(categorized);
    expect(unread.patient).toBe(1);
    expect(unread.care_team).toBe(1);
    expect(unread.notification).toBe(0); // read
    expect(unread.reminder).toBe(1);
    expect(unread.followup).toBe(1);
    expect(unread.alert).toBe(1);
  });

  it('handles empty messages', () => {
    const categorized = categorizeMessages([]);
    expect(categorized.patient).toHaveLength(0);
    expect(categorized.alert).toHaveLength(0);
  });
});

describe('PatientCommunicationHub message types', () => {
  it('distinguishes patient messages from clinical notes', () => {
    const patientMsg: CommMessage = {
      id: '1', subject: 'Question', body: 'When is my appointment?',
      type: 'patient_message', category: 'patient', channel: 'in_app',
      status: 'delivered', readAt: null, createdAt: '2024-01-01T00:00:00Z'
    };
    const clinicalNote: CommMessage = {
      id: '2', subject: 'Note', body: 'Patient presents with fever',
      type: 'clinical_note', category: 'clinical', channel: 'in_app',
      status: 'delivered', readAt: null, createdAt: '2024-01-01T00:00:00Z'
    };

    const categorized = categorizeMessages([patientMsg, clinicalNote]);
    expect(categorized.patient).toHaveLength(1);
    expect(categorized.patient[0].type).toBe('patient_message');
    // clinical_note should not be categorized as patient message
    expect(categorized.patient.every(m => m.type !== 'clinical_note')).toBe(true);
  });

  it('distinguishes notifications from conversations', () => {
    const notification: CommMessage = {
      id: '1', subject: 'System', body: 'System update complete',
      type: 'notification', category: 'system', channel: 'in_app',
      status: 'delivered', readAt: null, createdAt: '2024-01-01T00:00:00Z'
    };
    const conversation: CommMessage = {
      id: '2', subject: 'Re: Question', body: 'Your appointment is at 2pm',
      type: 'patient_response', category: 'patient', channel: 'in_app',
      status: 'delivered', readAt: null, createdAt: '2024-01-01T01:00:00Z'
    };

    const categorized = categorizeMessages([notification, conversation]);
    expect(categorized.notification).toHaveLength(1);
    expect(categorized.patient).toHaveLength(1);
  });

  it('distinguishes reminders from alerts', () => {
    const reminder: CommMessage = {
      id: '1', subject: 'Reminder', body: 'Appointment tomorrow',
      type: 'reminder', category: 'reminder', channel: 'sms',
      status: 'sent', readAt: null, createdAt: '2024-01-01T00:00:00Z'
    };
    const alert: CommMessage = {
      id: '2', subject: 'Urgent', body: 'Critical lab result',
      type: 'urgent', category: 'alert', channel: 'push',
      status: 'delivered', readAt: null, createdAt: '2024-01-01T01:00:00Z'
    };

    const categorized = categorizeMessages([reminder, alert]);
    expect(categorized.reminder).toHaveLength(1);
    expect(categorized.alert).toHaveLength(1);
  });
});
