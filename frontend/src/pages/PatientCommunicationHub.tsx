/**
 * PatientCommunicationHub — Patient Communication & Care Relationships (Phase 84)
 *
 * Establishes one coherent communication layer around:
 *   PATIENT → ENCOUNTER → CARE TEAM → COMMUNICATION → ACTION → OUTCOME
 *
 * Distinguishes:
 * - PATIENT COMMUNICATION (patient-facing)
 * - INTERNAL CARE COMMUNICATION (staff coordination)
 * - OPERATIONAL NOTIFICATION (system events)
 * - REMINDER (time-based)
 * - FOLLOW-UP (workflow-driven)
 * - ALERT (action-required)
 *
 * Does NOT create a chat app inside the HMS.
 * Creates CONTEXTUAL COMMUNICATION connected to the care workflow.
 */

import { useCallback, useMemo, useState } from 'react';
import { useParams } from 'react-router-dom';
import { useTenant } from '../context/TenantContext';

import { patientsApi, portalApi } from '../api/endpoints';
import { useFetch } from '../hooks/useFetch';
import {
  Alert,
  Button,
  Card,
  EmptyState,
  Input,
  StatusChip,
  formatDateTime,
} from '../components/ui';
import {
  MessageSquare,
  Bell,
  AlertTriangle,
  Send,
  CheckCircle2,
  Users,
  Mail,
  Phone,
  MessageCircle,
  Eye,
  CalendarClock,
  ClipboardCheck,
} from 'lucide-react';
import './patient-communication.css';

// ─── Communication type taxonomy ───
interface CommMessage {
  id: string;
  subject: string;
  body: string;
  type: string;
  category: string;
  severity: string;
  senderId?: string;
  senderName?: string;
  recipientId?: string;
  recipientName?: string;
  patientId?: string;
  encounterId?: string;
  channel: string;
  status: string;
  readAt: string | null;
  createdAt: string;
}

// ─── Communication category definitions ───
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
    Icon: MessageSquare,
    color: '#3b82f6',
    bgColor: '#eff6ff',
    types: ['patient_message', 'patient_question', 'patient_response'],
    description: 'Messages from or to the patient',
  },
  {
    id: 'care_team',
    label: 'Care Coordination',
    Icon: Users,
    color: '#8b5cf6',
    bgColor: '#f5f3ff',
    types: ['handoff', 'clarification', 'coordination', 'consultation'],
    description: 'Internal care team communication',
  },
  {
    id: 'notification',
    label: 'Notifications',
    Icon: Bell,
    color: '#64748b',
    bgColor: '#f8fafc',
    types: ['notification', 'info', 'update'],
    description: 'System-generated notifications',
  },
  {
    id: 'reminder',
    label: 'Reminders',
    Icon: CalendarClock,
    color: '#f59e0b',
    bgColor: '#fffbeb',
    types: ['reminder', 'appointment_reminder', 'medication_reminder'],
    description: 'Time-based reminders',
  },
  {
    id: 'followup',
    label: 'Follow-ups',
    Icon: ClipboardCheck,
    color: '#ec4899',
    bgColor: '#fdf2f8',
    types: ['followup', 'follow_up', 'follow_up_reminder'],
    description: 'Workflow-driven follow-ups',
  },
  {
    id: 'alert',
    label: 'Alerts',
    Icon: AlertTriangle,
    color: '#ef4444',
    bgColor: '#fef2f2',
    types: ['alert', 'urgent', 'critical'],
    description: 'Action-required notifications',
  },
];



// ════════════════════════════════════════════════════════════════════════════
// COMMUNICATION CATEGORY CARD
// ════════════════════════════════════════════════════════════════════════════
function CommCategoryCard({
  category,
  count,
  isActive,
  onClick,
}: {
  category: CommCategory;
  count: number;
  isActive: boolean;
  onClick: () => void;
}) {
  return (
    <button
      type="button"
      className={`comm-hub__category ${isActive ? 'comm-hub__category--active' : ''}`}
      onClick={onClick}
      aria-label={`${category.label} (${count})`}
      data-testid={`comm-category-${category.id}`}
    >
      <div
        className="comm-hub__category-icon"
        style={{ backgroundColor: category.bgColor, color: category.color }}
      >
        <category.Icon size={20} strokeWidth={1.75} />
      </div>
      <div className="comm-hub__category-info">
        <span className="comm-hub__category-label">{category.label}</span>
        {count > 0 && (
          <span className="comm-hub__category-count" style={{ backgroundColor: category.bgColor, color: category.color }}>
            {count}
          </span>
        )}
      </div>
      <span className="comm-hub__category-desc">{category.description}</span>
    </button>
  );
}

// ════════════════════════════════════════════════════════════════════════════
// COMMUNICATION MESSAGE CARD
// ════════════════════════════════════════════════════════════════════════════
function CommMessageCard({
  message,
  category,
  onMarkRead,
}: {
  message: CommMessage;
  category: CommCategory;
  onMarkRead?: (id: string) => void;
}) {
  const channelIcon = (ch: string) => {
    switch (ch) {
      case 'email': return <Mail size={12} />;
      case 'sms': return <Phone size={12} />;
      case 'whatsapp': return <MessageCircle size={12} />;
      default: return <MessageSquare size={12} />;
    }
  };

  const statusInfo = (s: string) => {
    switch (s) {
      case 'sent': return { tone: 'info' as const, label: 'Sent' };
      case 'delivered': return { tone: 'success' as const, label: 'Delivered' };
      case 'failed': return { tone: 'danger' as const, label: 'Failed' };
      case 'read': return { tone: 'success' as const, label: 'Read' };
      default: return { tone: 'neutral' as const, label: s };
    }
  };

  return (
    <div className={`comm-hub__message ${!message.readAt ? 'comm-hub__message--unread' : ''}`}>
      <div className="comm-hub__message-header">
        <div className="comm-hub__message-icon" style={{ backgroundColor: category.bgColor, color: category.color }}>
          <category.Icon size={14} />
        </div>
        <div className="comm-hub__message-meta">
          <span className="comm-hub__message-subject">{message.subject || 'No subject'}</span>
          <div className="comm-hub__message-sender">
            {message.senderName && <span>{message.senderName}</span>}
            <span className="comm-hub__message-channel">{channelIcon(message.channel)} {message.channel}</span>
          </div>
        </div>
        <div className="comm-hub__message-status">
          <StatusChip {...statusInfo(message.status)} />
          <span className="comm-hub__message-time">{formatDateTime(message.createdAt)}</span>
        </div>
      </div>
      <div className="comm-hub__message-body">{message.body}</div>
      {!message.readAt && onMarkRead && (
        <button
          className="comm-hub__message-read"
          onClick={() => onMarkRead(message.id)}
          aria-label="Mark as read"
        >
          <Eye size={12} />
          Mark as read
        </button>
      )}
    </div>
  );
}

// ════════════════════════════════════════════════════════════════════════════
// CARE TEAM PANEL
// ════════════════════════════════════════════════════════════════════════════
function CareTeamPanel() {
  return (
    <Card title="Care Team" className="comm-hub__care-team">
      <div className="comm-hub__care-team-empty">
        <Users size={24} className="comm-hub__care-team-icon" />
        <p className="comm-hub__care-team-text">
          Care team relationships are managed through encounters and referrals.
          Each care episode maintains its own authorized care team.
        </p>
        <p className="comm-hub__care-team-hint">
          When a clinician is assigned to an encounter, they become part of that
          patient's active care team for the duration of the episode.
        </p>
      </div>
    </Card>
  );
}

// ════════════════════════════════════════════════════════════════════════════
// COMPOSE MESSAGE DIALOG
// ════════════════════════════════════════════════════════════════════════════
function ComposeMessageDialog({
  patientName,
  onClose,
  onSent,
}: {
  patientName: string;
  onClose: () => void;
  onSent: () => void;
}) {
  const [recipientId, setRecipientId] = useState('');
  const [subject, setSubject] = useState('');
  const [body, setBody] = useState('');
  const [category, setCategory] = useState('general');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState(false);

  const handleSend = async () => {
    if (!recipientId || !subject.trim() || !body.trim()) return;
    setBusy(true);
    setError(null);
    try {
      await portalApi.sendMessage({
        recipientStaffId: recipientId,
        subject: subject.trim(),
        body: body.trim(),
        category,
      });
      setSuccess(true);
      setTimeout(() => { onSent(); onClose(); }, 1500);
    } catch (err: any) {
      setError(err?.message || 'Failed to send message');
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="comm-hub__dialog-overlay" onClick={onClose}>
      <div className="comm-hub__dialog" onClick={(e) => e.stopPropagation()} role="dialog" aria-modal="true" aria-label="Compose message">
        <div className="comm-hub__dialog-header">
          <h3>Send Message</h3>
          <span className="comm-hub__dialog-context">Regarding: {patientName}</span>
        </div>

        {error && <Alert tone="danger">{error}</Alert>}
        {success && (
          <Alert tone="success">
            <CheckCircle2 size={14} /> Message sent successfully
          </Alert>
        )}

        {!success && (
          <div className="comm-hub__dialog-body">
            <Input
              label="Recipient Staff ID"
              value={recipientId}
              onChange={(e) => setRecipientId(e.target.value)}
              placeholder="Staff member UUID"
              required
            />
            <Input
              label="Subject"
              value={subject}
              onChange={(e) => setSubject(e.target.value)}
              placeholder={`Regarding ${patientName}`}
              required
            />
            <div className="comm-hub__field">
              <label className="comm-hub__label">Message</label>
              <textarea
                className="comm-hub__textarea"
                value={body}
                onChange={(e) => setBody(e.target.value)}
                placeholder={`Write your message about ${patientName}...`}
                rows={5}
                required
              />
            </div>
            <div className="comm-hub__field">
              <label className="comm-hub__label">Category</label>
              <select
                className="comm-hub__select"
                value={category}
                onChange={(e) => setCategory(e.target.value)}
              >
                <option value="general">General</option>
                <option value="clinical">Clinical</option>
                <option value="appointment">Appointment</option>
                <option value="billing">Billing</option>
                <option value="support">Support</option>
              </select>
            </div>
          </div>
        )}

        <div className="comm-hub__dialog-footer">
          <Button variant="ghost" onClick={onClose}>
            {success ? 'Close' : 'Cancel'}
          </Button>
          {!success && (
            <Button
              onClick={handleSend}
              loading={busy}
              disabled={!recipientId || !subject.trim() || !body.trim()}
            >
              <Send size={14} /> Send Message
            </Button>
          )}
        </div>
      </div>
    </div>
  );
}

// ════════════════════════════════════════════════════════════════════════════
// MAIN PATIENT COMMUNICATION HUB
// ════════════════════════════════════════════════════════════════════════════
export function PatientCommunicationHub() {
  const { id: patientId } = useParams<{ id: string }>();


  const [activeCategory, setActiveCategory] = useState<string | null>(null);
  const [showCompose, setShowCompose] = useState(false);
  const [showCareTeam, setShowCareTeam] = useState(false);

  const { selectedFacilityId } = useTenant();

  // Fetch notifications/messages
  const notifications = useFetch(
    () => portalApi.messages().catch(() => []),
    [],
  );



  // Fetch patient-specific messages (from portal)
  const patientMessages = useFetch(
    () => portalApi.messages().catch(() => []),
    [],
  );

  // Group messages by category
  const categorizedMessages = useMemo(() => {
    const patientMsgs = Array.isArray(patientMessages.data) ? patientMessages.data : [];
    const notifMsgs = Array.isArray(notifications.data) ? notifications.data : [];
    const all = [...patientMsgs, ...notifMsgs] as CommMessage[];

    const result: Record<string, CommMessage[]> = {};
    for (const cat of COMM_CATEGORIES) {
      result[cat.id] = all.filter(msg =>
        cat.types.includes(msg.type) ||
        cat.types.includes(msg.category) ||
        (cat.id === 'patient' && (msg.patientId === patientId || msg.type === 'patient_message'))
      );
    }
    return result;
  }, [patientMessages.data, notifications.data, patientId]);

  // Count unread per category
  const unreadCounts = useMemo(() => {
    const counts: Record<string, number> = {};
    for (const [catId, msgs] of Object.entries(categorizedMessages)) {
      counts[catId] = msgs.filter(m => !m.readAt).length;
    }
    return counts;
  }, [categorizedMessages]);

  const totalUnread = useMemo(
    () => Object.values(unreadCounts).reduce((a, b) => a + b, 0),
    [unreadCounts],
  );

  const patient = useFetch(
    () => patientsApi.show(patientId!, selectedFacilityId),
    [patientId, selectedFacilityId],
  );

  const patientName = (patient.data as any)?.fullName || 'Patient';

  const markRead = useCallback(async (_id: string) => {
    notifications.refresh();
    patientMessages.refresh();
  }, [notifications, patientMessages]);

  const filteredMessages = activeCategory ? categorizedMessages[activeCategory] || [] : [];

  return (
    <div className="comm-hub" data-testid="patient-communication-hub">
      {/* Header */}
      <div className="comm-hub__header">
        <div className="comm-hub__header-info">
          <h2 className="comm-hub__title">Communication</h2>
          {totalUnread > 0 && (
            <span className="comm-hub__unread-badge">{totalUnread} unread</span>
          )}
        </div>
        <div className="comm-hub__actions">
          <Button
            variant="ghost"
            size="sm"
            onClick={() => setShowCareTeam(!showCareTeam)}
          >
            <Users size={14} /> Care Team
          </Button>
          <Button
            variant="primary"
            size="sm"
            onClick={() => setShowCompose(true)}
          >
            <Send size={14} /> Message
          </Button>
        </div>
      </div>

      {/* Care Team Panel (toggleable) */}
      {showCareTeam && (          <CareTeamPanel />
      )}

      {/* Communication Categories */}
      <div className="comm-hub__categories" role="navigation" aria-label="Communication categories">
        {COMM_CATEGORIES.map((cat) => (
          <CommCategoryCard
            key={cat.id}
            category={cat}
            count={unreadCounts[cat.id] || 0}
            isActive={activeCategory === cat.id}
            onClick={() => setActiveCategory(activeCategory === cat.id ? null : cat.id)}
          />
        ))}
      </div>

      {/* Messages List */}
      <div className="comm-hub__messages">
        {activeCategory ? (
          filteredMessages.length > 0 ? (
            filteredMessages.map((msg) => {
              const cat = COMM_CATEGORIES.find(c => c.id === activeCategory)!;
              return (
                <CommMessageCard
                  key={msg.id}
                  message={msg}
                  category={cat}
                  onMarkRead={markRead}
                />
              );
            })
          ) : (
            <EmptyState
              title={`No ${COMM_CATEGORIES.find(c => c.id === activeCategory)?.label || 'messages'}`}
              body="Messages in this category will appear here."
            />
          )
        ) : (
          <div className="comm-hub__empty-state">
            <MessageSquare size={32} className="comm-hub__empty-icon" />
            <h3>Communication Hub</h3>
            <p>
              Select a category above to view messages, or compose a new message
              regarding this patient.
            </p>
            <div className="comm-hub__empty-categories">
              {COMM_CATEGORIES.map((cat) => (
                <button
                  key={cat.id}
                  className="comm-hub__empty-category"
                  onClick={() => setActiveCategory(cat.id)}
                >
                  <cat.Icon size={16} style={{ color: cat.color }} />
                  <span>{cat.label}</span>
                </button>
              ))}
            </div>
          </div>
        )}
      </div>

      {/* Compose Dialog */}
      {showCompose && (
        <ComposeMessageDialog
          patientName={patientName}
          onClose={() => setShowCompose(false)}
          onSent={() => {
            notifications.refresh();
            patientMessages.refresh();
          }}
        />
      )}
    </div>
  );
}

export default PatientCommunicationHub;
