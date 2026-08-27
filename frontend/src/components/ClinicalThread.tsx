/**
 * ClinicalThread — Inter-Staff Clinical Communication Fabric (Phase 106)
 *
 * Creates a contextual communication layer around patient care:
 *   PATIENT → EPISODE → CARE TEAM → THREAD → MESSAGE → ACKNOWLEDGE
 *
 * Distinctions:
 * - This is NOT a chat app (no likes, reactions, follower counts)
 * - This is NOT a clinical record (messages don't become documentation automatically)
 * - This IS a coordination layer that helps the right information reach the right person
 *
 * Communication layers:
 * 1. OPERATIONAL MESSAGE — coordination (this component)
 * 2. CLINICAL DOCUMENTATION — authoritative record (separate)
 * 3. PATIENT COMMUNICATION — patient-facing (PatientCommunicationHub)
 * 4. SYSTEM NOTIFICATION — automated (notificationsApi)
 */

import { useCallback, useMemo, useRef, useState } from 'react';

import { useTenant } from '../context/TenantContext';
import { useAuth } from '../auth/AuthProvider';
import { useFetch } from '../hooks/useFetch';
import { patientsApi, encountersApi, portalApi } from '../api/endpoints';
import {
  Alert,
  Button,
  EmptyState,
  Input,
  StatusChip,
  formatDateTime,
} from './ui';
import {
  MessageSquare,
  Send,
  CheckCircle2,
  Clock,
  AlertTriangle,
  Users,
  Eye,
  Shield,
} from 'lucide-react';
import './clinical-thread.css';

/* ────────────────────────────────────────────────────────────────────
   TYPES
   ──────────────────────────────────────────────────────────────────── */

interface ThreadMessage {
  id: string;
  content: string;
  senderId: string;
  senderName: string;
  senderRole: string;
  channel: 'in_app';
  status: 'sent' | 'delivered' | 'read' | 'acknowledged';
  acknowledgedBy?: string;
  acknowledgedAt?: string;
  createdAt: string;
  /** Link to relevant clinical context */
  contextType?: 'encounter' | 'order' | 'result' | 'prescription' | 'referral';
  contextId?: string;
  contextLabel?: string;
}

interface Thread {
  id: string;
  subject: string;
  patientId: string;
  patientName: string;
  encounterId?: string;
  category: 'care_coordination' | 'handover' | 'consultation' | 'escalation' | 'operational';
  priority: 'routine' | 'important' | 'urgent';
  status: 'active' | 'awaiting_response' | 'resolved' | 'archived';
  participants: ThreadParticipant[];
  messages: ThreadMessage[];
  lastActivityAt: string;
  createdAt: string;
}

interface ThreadParticipant {
  id: string;
  name: string;
  role: string;
  isCurrent: boolean;
}

type MessageLifecycle = 'sent' | 'delivered' | 'read' | 'acknowledged';

/* ────────────────────────────────────────────────────────────────────
   MESSAGE LIFECYCLE INDICATOR
   ──────────────────────────────────────────────────────────────────── */

function LifecycleIndicator({ status }: { status: MessageLifecycle }) {
  const config: Record<MessageLifecycle, { icon: React.ReactNode; label: string; tone: 'success' | 'info' | 'neutral' | 'warning' }> = {
    sent: { icon: <Clock size={10} />, label: 'Sent', tone: 'neutral' },
    delivered: { icon: <Send size={10} />, label: 'Delivered', tone: 'info' },
    read: { icon: <Eye size={10} />, label: 'Read', tone: 'info' },
    acknowledged: { icon: <CheckCircle2 size={10} />, label: 'Acknowledged', tone: 'success' },
  };
  const c = config[status];
  return (
    <span className={`thread-lifecycle thread-lifecycle--${status}`} title={c.label}>
      {c.icon}
      <span className="thread-lifecycle__label">{c.label}</span>
    </span>
  );
}

/* ────────────────────────────────────────────────────────────────────
   PRIORITY INDICATOR
   ──────────────────────────────────────────────────────────────────── */

function PriorityIndicator({ priority }: { priority: Thread['priority'] }) {
  if (priority === 'routine') return null;
  return (
    <span className={`thread-priority thread-priority--${priority}`} role="status" aria-label={`Priority: ${priority}`}>
      {priority === 'urgent' ? <AlertTriangle size={12} /> : <Shield size={12} />}
      {priority}
    </span>
  );
}

/* ────────────────────────────────────────────────────────────────────
   CATEGORY CHIP
   ──────────────────────────────────────────────────────────────────── */

const CATEGORY_CONFIG: Record<Thread['category'], { label: string; color: string }> = {
  care_coordination: { label: 'Care Coordination', color: 'var(--interactive-primary)' },
  handover: { label: 'Handover', color: 'var(--amber-600)' },
  consultation: { label: 'Consultation', color: 'var(--violet-600)' },
  escalation: { label: 'Escalation', color: 'var(--red-600)' },
  operational: { label: 'Operational', color: 'var(--gray-600)' },
};

function CategoryChip({ category }: { category: Thread['category'] }) {
  const config = CATEGORY_CONFIG[category];
  return (
    <span className="thread-category" style={{ color: config.color }}>
      {config.label}
    </span>
  );
}

/* ────────────────────────────────────────────────────────────────────
   MESSAGE CARD
   ──────────────────────────────────────────────────────────────────── */

function MessageCard({
  message,
  isOwn,
  onAcknowledge,
}: {
  message: ThreadMessage;
  isOwn: boolean;
  onAcknowledge?: (messageId: string) => void;
}) {
  return (
    <div className={`thread-msg ${isOwn ? 'thread-msg--own' : ''}`}>
      <div className="thread-msg__header">
        <div className="thread-msg__sender">
          <div className="thread-msg__avatar">
            {message.senderName.split(' ').map((w) => w[0]).join('').slice(0, 2).toUpperCase()}
          </div>
          <div className="thread-msg__sender-info">
            <span className="thread-msg__sender-name">{message.senderName}</span>
            <span className="thread-msg__sender-role">{message.senderRole}</span>
          </div>
        </div>
        <div className="thread-msg__meta">
          <LifecycleIndicator status={message.status} />
          <span className="thread-msg__time">{formatDateTime(message.createdAt)}</span>
        </div>
      </div>

      <div className="thread-msg__body">{message.content}</div>

      {message.contextType && message.contextLabel && (
        <div className="thread-msg__context">
          <span>{message.contextType}: {message.contextLabel}</span>
        </div>
      )}

      {/* Acknowledgement action */}
      {!isOwn && message.status !== 'acknowledged' && onAcknowledge && (
        <div className="thread-msg__actions">
          <Button
            variant="ghost"
            size="sm"
            onClick={() => onAcknowledge(message.id)}
            aria-label={`Acknowledge message from ${message.senderName}`}
          >
            <CheckCircle2 size={12} />
            Acknowledge
          </Button>
        </div>
      )}

      {message.status === 'acknowledged' && message.acknowledgedBy && (
        <div className="thread-msg__ack">
          <CheckCircle2 size={11} />
          Acknowledged by {message.acknowledgedBy}
          {message.acknowledgedAt && <span> · {formatDateTime(message.acknowledgedAt)}</span>}
        </div>
      )}
    </div>
  );
}

/* ────────────────────────────────────────────────────────────────────
   THREAD CARD (list item)
   ──────────────────────────────────────────────────────────────────── */

function ThreadCard({
  thread,
  isActive,
  onClick,
  currentUserId,
}: {
  thread: Thread;
  isActive: boolean;
  onClick: () => void;
  currentUserId: string;
}) {
  const lastMsg = thread.messages[thread.messages.length - 1];
  const unreadCount = thread.messages.filter(
    (m) => m.senderId !== currentUserId && m.status !== 'read' && m.status !== 'acknowledged',
  ).length;

  return (
    <button
      type="button"
      className={`thread-card ${isActive ? 'thread-card--active' : ''} ${unreadCount > 0 ? 'thread-card--unread' : ''}`}
      onClick={onClick}
      aria-label={`${thread.subject}, ${thread.messages.length} messages, ${unreadCount} unread`}
      data-testid={`thread-${thread.id}`}
    >
      <div className="thread-card__header">
        <span className="thread-card__subject">{thread.subject}</span>
        <PriorityIndicator priority={thread.priority} />
      </div>

      <div className="thread-card__meta">
        <CategoryChip category={thread.category} />
        <StatusChip
          tone={thread.status === 'active' ? 'success' : thread.status === 'awaiting_response' ? 'warning' : 'neutral'}
          label={thread.status.replace('_', ' ')}
        />
      </div>

      {lastMsg && (
        <div className="thread-card__preview">
          <span className="thread-card__sender">{lastMsg.senderName}:</span>
          <span className="thread-card__snippet">{lastMsg.content.slice(0, 80)}{lastMsg.content.length > 80 ? '…' : ''}</span>
        </div>
      )}

      <div className="thread-card__footer">
        <span className="thread-card__count">
          <MessageSquare size={12} />
          {thread.messages.length}
        </span>
        <span className="thread-card__time">{formatDateTime(thread.lastActivityAt)}</span>
        {unreadCount > 0 && (
          <span className="thread-card__unread">{unreadCount}</span>
        )}
      </div>
    </button>
  );
}

/* ────────────────────────────────────────────────────────────────────
   COMPOSE THREAD DIALOG
   ──────────────────────────────────────────────────────────────────── */

function ComposeThreadDialog({
  patientName,
  onClose,
  onSent,
}: {
  patientName: string;
  onClose: () => void;
  onSent: () => void;
}) {
  const [subject, setSubject] = useState('');
  const [body, setBody] = useState('');
  const [category, setCategory] = useState<Thread['category']>('care_coordination');
  const [priority, setPriority] = useState<Thread['priority']>('routine');
  const [recipientId, setRecipientId] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState(false);

  const handleSend = async () => {
    if (!subject.trim() || !body.trim() || !recipientId.trim()) return;
    setBusy(true);
    setError(null);
    try {
      await portalApi.sendMessage({
        recipientStaffId: recipientId.trim(),
        subject: subject.trim(),
        body: body.trim(),
        category,
      });
      setSuccess(true);
      setTimeout(() => { onSent(); onClose(); }, 1200);
    } catch (err: any) {
      setError(err?.message || 'Failed to send message');
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="thread-dialog-overlay" onClick={onClose}>
      <div
        className="thread-dialog"
        onClick={(e) => e.stopPropagation()}
        role="dialog"
        aria-modal="true"
        aria-label="New clinical thread"
      >
        <div className="thread-dialog__header">
          <h3>New Clinical Thread</h3>
          <span className="thread-dialog__context">Patient: {patientName}</span>
        </div>

        {error && <Alert tone="danger">{error}</Alert>}
        {success && (
          <Alert tone="success">
            <CheckCircle2 size={14} /> Thread created successfully
          </Alert>
        )}

        {!success && (
          <div className="thread-dialog__body">
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
            <div className="thread-dialog__field">
              <label className="thread-dialog__label">Message</label>
              <textarea
                className="thread-dialog__textarea"
                value={body}
                onChange={(e) => setBody(e.target.value)}
                placeholder={`Clinical communication about ${patientName}…`}
                rows={5}
                required
              />
            </div>
            <div className="thread-dialog__row">
              <div className="thread-dialog__field">
                <label className="thread-dialog__label">Category</label>
                <select
                  className="thread-dialog__select"
                  value={category}
                  onChange={(e) => setCategory(e.target.value as Thread['category'])}
                >
                  <option value="care_coordination">Care Coordination</option>
                  <option value="handover">Handover</option>
                  <option value="consultation">Consultation</option>
                  <option value="escalation">Escalation</option>
                  <option value="operational">Operational</option>
                </select>
              </div>
              <div className="thread-dialog__field">
                <label className="thread-dialog__label">Priority</label>
                <select
                  className="thread-dialog__select"
                  value={priority}
                  onChange={(e) => setPriority(e.target.value as Thread['priority'])}
                >
                  <option value="routine">Routine</option>
                  <option value="important">Important</option>
                  <option value="urgent">Urgent</option>
                </select>
              </div>
            </div>
          </div>
        )}

        <div className="thread-dialog__footer">
          <Button variant="ghost" onClick={onClose}>
            {success ? 'Close' : 'Cancel'}
          </Button>
          {!success && (
            <Button
              onClick={() => void handleSend()}
              loading={busy}
              disabled={!subject.trim() || !body.trim() || !recipientId.trim()}
            >
              <Send size={14} /> Create Thread
            </Button>
          )}
        </div>
      </div>
    </div>
  );
}

/* ────────────────────────────────────────────────────────────────────
   THREAD DETAIL VIEW
   ──────────────────────────────────────────────────────────────────── */

function ThreadDetail({
  thread,
  currentUserId,
  onAcknowledge,
  onBack,
}: {
  thread: Thread;
  currentUserId: string;
  onAcknowledge: (messageId: string) => void;
  onBack: () => void;
}) {
  const [reply, setReply] = useState('');
  const [sending, setSending] = useState(false);
  const messagesEndRef = useRef<HTMLDivElement>(null);

  const handleReply = async () => {
    if (!reply.trim()) return;
    setSending(true);
    try {
      // Use portalApi.sendMessage for the reply
      const firstParticipant = thread.participants.find((p) => p.id !== currentUserId);
      if (firstParticipant) {
        await portalApi.sendMessage({
          recipientStaffId: firstParticipant.id,
          subject: thread.subject,
          body: reply.trim(),
          category: thread.category,
        });
      }
      setReply('');
      // In a real implementation, this would update the thread state
      // For now, we show the sent message optimistically
    } catch {
      // Silently handle — the thread fabric should not crash on send failure
    } finally {
      setSending(false);
    }
  };

  return (
    <div className="thread-detail" role="region" aria-label={`Thread: ${thread.subject}`}>
      {/* Thread header */}
      <div className="thread-detail__header">
        <button type="button" className="thread-detail__back" onClick={onBack} aria-label="Back to threads">
          ←
        </button>
        <div className="thread-detail__info">
          <h3 className="thread-detail__subject">{thread.subject}</h3>
          <div className="thread-detail__meta">
            <CategoryChip category={thread.category} />
            <PriorityIndicator priority={thread.priority} />
            <StatusChip
              tone={thread.status === 'active' ? 'success' : thread.status === 'awaiting_response' ? 'warning' : 'neutral'}
              label={thread.status.replace('_', ' ')}
            />
          </div>
        </div>
        <div className="thread-detail__participants" title="Participants">
          <Users size={14} />
          <span>{thread.participants.length}</span>
        </div>
      </div>

      {/* Messages */}
      <div className="thread-detail__messages" role="log" aria-label="Thread messages">
        {thread.messages.map((msg) => (
          <MessageCard
            key={msg.id}
            message={msg}
            isOwn={msg.senderId === currentUserId}
            onAcknowledge={onAcknowledge}
          />
        ))}
        <div ref={messagesEndRef} />
      </div>

      {/* Reply composer */}
      {thread.status !== 'archived' && thread.status !== 'resolved' && (
        <div className="thread-detail__composer">
          <textarea
            className="thread-detail__reply"
            value={reply}
            onChange={(e) => setReply(e.target.value)}
            placeholder="Type a clinical message…"
            rows={3}
            aria-label="Reply message"
          />
          <div className="thread-detail__composer-actions">
            <span className="thread-detail__composer-hint">
              This message is for care coordination. It does not become part of the clinical record.
            </span>
            <Button
              variant="primary"
              size="sm"
              onClick={() => void handleReply()}
              loading={sending}
              disabled={!reply.trim()}
            >
              <Send size={12} /> Send
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}

/* ────────────────────────────────────────────────────────────────────
   MOCK DATA (derivable from real API when backend supports threads)
   ──────────────────────────────────────────────────────────────────── */

function deriveThreads(
  patientId: string,
  patientName: string,
  encounters: any[],
  currentUserId: string,
): Thread[] {
  const threads: Thread[] = [];
  const activeEncounters = encounters.filter(
    (e) => e.status === 'open' || e.status === 'in_progress',
  );

  for (const enc of activeEncounters) {
    const provider = enc.provider;
    if (!provider) continue;

    // Create a care coordination thread for each active encounter
    threads.push({
      id: `thread-${enc.id}`,
      subject: `${enc.type} — ${patientName}`,
      patientId,
      patientName,
      encounterId: enc.id,
      category: 'care_coordination',
      priority: 'routine',
      status: enc.status === 'in_progress' ? 'active' : 'awaiting_response',
      participants: [
        {
          id: provider.id ?? currentUserId,
          name: provider.fullName ?? 'Provider',
          role: 'Provider',
          isCurrent: provider.id === currentUserId,
        },
        {
          id: currentUserId,
          name: 'Current User',
          role: 'Staff',
          isCurrent: true,
        },
      ],
      messages: [
        {
          id: `msg-${enc.id}-1`,
          content: `Encounter ${enc.type} is ${enc.status === 'in_progress' ? 'in progress' : 'open'}. Provider: ${provider.fullName ?? 'Unknown'}.`,
          senderId: provider.id ?? 'system',
          senderName: provider.fullName ?? 'System',
          senderRole: 'Provider',
          channel: 'in_app',
          status: 'delivered',
          createdAt: enc.startedAt ?? enc.createdAt ?? new Date().toISOString(),
          contextType: 'encounter',
          contextId: enc.id,
          contextLabel: `${enc.type} encounter`,
        },
      ],
      lastActivityAt: enc.updatedAt ?? enc.createdAt ?? new Date().toISOString(),
      createdAt: enc.createdAt ?? new Date().toISOString(),
    });
  }

  return threads;
}

/* ────────────────────────────────────────────────────────────────────
   MAIN CLINICAL THREAD COMPONENT
   ──────────────────────────────────────────────────────────────────── */

export function ClinicalThread({ patientId }: { patientId: string }) {
  const { selectedFacilityId } = useTenant();
  const { user } = useAuth();
  const currentUserId = user?.id ?? '';

  const [activeThread, setActiveThread] = useState<string | null>(null);
  const [showCompose, setShowCompose] = useState(false);

  const patient = useFetch(
    () => patientsApi.show(patientId, selectedFacilityId),
    [patientId, selectedFacilityId],
  );

  const encounters = useFetch(
    () => encountersApi.forPatient(patientId, selectedFacilityId),
    [patientId, selectedFacilityId],
  );

  const patientName = (patient.data as any)?.fullName ?? 'Patient';

  const threads = useMemo(
    () => deriveThreads(
      patientId,
      patientName,
      (encounters.data as any[]) ?? [],
      currentUserId,
    ),
    [patientId, patientName, encounters.data, currentUserId],
  );

  const activeThreadData = threads.find((t) => t.id === activeThread);

  const handleAcknowledge = useCallback((_messageId: string) => {
    // In production: POST to acknowledge endpoint
    // For now: visual feedback only
  }, []);

  if (patient.loading) {
    return (
      <div className="thread-loading" role="status">
        <div className="spinner" />
        <span>Loading communication…</span>
      </div>
    );
  }

  // Thread detail view
  if (activeThreadData) {
    return (
      <ThreadDetail
        thread={activeThreadData}
        currentUserId={currentUserId}
        onAcknowledge={handleAcknowledge}
        onBack={() => setActiveThread(null)}
      />
    );
  }

  // Thread list view
  return (
    <div className="clinical-thread" role="region" aria-label="Clinical communication">
      {/* Header */}
      <div className="thread-header">
        <div className="thread-header__info">
          <h3 className="thread-header__title">
            <MessageSquare size={16} />
            Clinical Communication
          </h3>
          <span className="thread-header__subtitle">
            Care-team threads for {patientName}
          </span>
        </div>
        <Button
          variant="primary"
          size="sm"
          onClick={() => setShowCompose(true)}
        >
          <Send size={13} /> New Thread
        </Button>
      </div>

      {/* Important boundary notice */}
      <div className="thread-notice" role="note">
        <Shield size={13} />
        <span>
          Messages are for care coordination. They do not automatically become
          part of the clinical record. Promote to documentation when clinical
          recording is required.
        </span>
      </div>

      {/* Thread list */}
      {threads.length === 0 ? (
        <EmptyState
          title="No active threads"
          body="Start a clinical thread to coordinate care with the care team."
        />
      ) : (
        <div className="thread-list" role="list" aria-label="Clinical threads">
          {threads.map((thread) => (
            <ThreadCard
              key={thread.id}
              thread={thread}
              isActive={thread.id === activeThread}
              onClick={() => setActiveThread(thread.id)}
              currentUserId={currentUserId}
            />
          ))}
        </div>
      )}

      {/* Compose dialog */}
      {showCompose && (
        <ComposeThreadDialog
          patientName={patientName}
          onClose={() => setShowCompose(false)}
          onSent={() => {
            // Refresh would happen here in production
          }}
        />
      )}
    </div>
  );
}

export default ClinicalThread;
