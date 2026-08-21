import { useState, useEffect, useCallback, useRef } from 'react';
import { useTenant } from '../context/TenantContext';
import { useAuth } from '../auth/AuthProvider';
import { realtimeApi } from '../api/endpoints';
import { Button, EmptyState } from '../components/ui';
import {
  Bell,
  BellRing,
  CheckCircle,
  AlertTriangle,
  Siren,
  Info,
  X,
  Eye,
  EyeOff,
  RefreshCw,
  Wifi,
  WifiOff,
  Shield,
} from 'lucide-react';
import './operations-center.css';

type Tab = 'all' | 'unread' | 'critical' | 'acknowledged';

interface RealtimeEventItem {
  id: string;
  receiptId: string;
  eventType: string;
  category: string;
  severity: string;
  priority: string;
  title: string;
  message: string | null;
  actionUrl: string | null;
  channel: string;
  receiptStatus: string;
  deliveredAt: string;
  readAt: string | null;
  acknowledgedAt: string | null;
  acknowledgementNote: string | null;
  acknowledgementRequired: boolean;
  createdAt: string;
}

const SEVERITY_CONFIG: Record<string, { icon: typeof Bell; color: string; bg: string; label: string }> = {
  info: { icon: Info, color: '#2563eb', bg: '#eff6ff', label: 'Info' },
  warning: { icon: AlertTriangle, color: '#d97706', bg: '#fffbeb', label: 'Warning' },
  urgent: { icon: Siren, color: '#ea580c', bg: '#fff7ed', label: 'Urgent' },
  critical: { icon: Siren, color: '#dc2626', bg: '#fef2f2', label: 'Critical' },
};

const CATEGORY_LABELS: Record<string, string> = {
  appointment: 'Appointment',
  clinical: 'Clinical',
  pharmacy: 'Pharmacy',
  billing: 'Billing',
  admin: 'Admin',
  system: 'System',
};

export function OperationsCenterPage() {
  const { selectedFacilityId } = useTenant();
  const { } = useAuth();

  const [events, setEvents] = useState<RealtimeEventItem[]>([]);
  const [, setTotal] = useState(0);
  const [unreadCount, setUnreadCount] = useState(0);
  const [severityCounts, setSeverityCounts] = useState<Record<string, number>>({});
  const [activeTab, setActiveTab] = useState<Tab>('all');
  const [loading, setLoading] = useState(true);
  const [connected, setConnected] = useState(false);
  const [categoryFilter, setCategoryFilter] = useState<string>('all');
  const [selectedEvent, setSelectedEvent] = useState<RealtimeEventItem | null>(null);
  const eventSourceRef = useRef<EventSource | null>(null);
  const reconnectTimeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  const fetchEvents = useCallback(async () => {
    try {
      const params: Record<string, string | number> = {};
      if (selectedFacilityId) params.facilityId = selectedFacilityId;
      if (categoryFilter !== 'all') params.category = categoryFilter;
      if (activeTab === 'unread') params.eventStatus = 'unread';
      if (activeTab === 'critical') params.severity = 'critical';
      params.limit = 100;

      const res = await realtimeApi.events(params);
      const data = res as unknown as { events: RealtimeEventItem[]; total: number; unreadCount: number };
      setEvents(data.events ?? []);
      setTotal(data.total ?? 0);
      setUnreadCount(data.unreadCount ?? 0);
    } catch {
      // handled
    }
  }, [selectedFacilityId, categoryFilter, activeTab]);

  const fetchCounts = useCallback(async () => {
    try {
      const [unread, sev] = await Promise.all([
        realtimeApi.unreadCount(selectedFacilityId ?? undefined),
        realtimeApi.severityCounts(selectedFacilityId ?? undefined),
      ]);
      setUnreadCount((unread as unknown as { count: number }).count);
      setSeverityCounts(sev as unknown as Record<string, number>);
    } catch {
      // handled
    }
  }, [selectedFacilityId]);

  // SSE connection with reconnect
  const connectSSE = useCallback(() => {
    if (eventSourceRef.current) {
      eventSourceRef.current.close();
    }

    try {
      const es = realtimeApi.stream();
      eventSourceRef.current = es;

      es.addEventListener('connected', () => {
        setConnected(true);
      });

      es.addEventListener('notification', (e) => {
        try {
          const data = JSON.parse(e.data) as RealtimeEventItem;
          setEvents((prev) => [data, ...prev]);
          setUnreadCount((prev) => prev + 1);
          setSeverityCounts((prev) => ({
            ...prev,
            [data.severity]: (prev[data.severity] ?? 0) + 1,
          }));
        } catch {
          // malformed event
        }
      });

      es.addEventListener('heartbeat', () => {
        setConnected(true);
      });

      es.addEventListener('disconnected', () => {
        setConnected(false);
        scheduleReconnect();
      });

      es.onerror = () => {
        setConnected(false);
        es.close();
        scheduleReconnect();
      };
    } catch {
      setConnected(false);
      scheduleReconnect();
    }
  }, []);

  const scheduleReconnect = useCallback(() => {
    if (reconnectTimeoutRef.current) {
      clearTimeout(reconnectTimeoutRef.current);
    }
    reconnectTimeoutRef.current = setTimeout(() => {
      connectSSE();
    }, 5000);
  }, [connectSSE]);

  useEffect(() => {
    setLoading(true);
    Promise.all([fetchEvents(), fetchCounts()]).then(() => setLoading(false));
    connectSSE();

    // Polling fallback every 30s
    const pollInterval = setInterval(() => {
      fetchCounts();
    }, 30000);

    return () => {
      eventSourceRef.current?.close();
      if (reconnectTimeoutRef.current) clearTimeout(reconnectTimeoutRef.current);
      clearInterval(pollInterval);
    };
  }, [fetchEvents, fetchCounts, connectSSE]);

  const handleMarkRead = async (eventIds: string[]) => {
    await realtimeApi.markRead(eventIds);
    setEvents((prev) =>
      prev.map((e) =>
        eventIds.includes(e.id)
          ? { ...e, readAt: new Date().toISOString(), receiptStatus: 'read' }
          : e,
      ),
    );
    setUnreadCount((prev) => Math.max(0, prev - eventIds.length));
  };

  const handleMarkAllRead = async () => {
    await realtimeApi.markAllRead(selectedFacilityId ?? undefined);
    setEvents((prev) =>
      prev.map((e) => ({
        ...e,
        readAt: e.readAt ?? new Date().toISOString(),
        receiptStatus: e.readAt ? e.receiptStatus : 'read',
      })),
    );
    setUnreadCount(0);
  };

  const handleAcknowledge = async (eventId: string, note?: string) => {
    await realtimeApi.acknowledge(eventId, note);
    setEvents((prev) =>
      prev.map((e) =>
        e.id === eventId
          ? { ...e, acknowledgedAt: new Date().toISOString(), receiptStatus: 'acknowledged', readAt: e.readAt ?? new Date().toISOString() }
          : e,
      ),
    );
    setUnreadCount((prev) => Math.max(0, prev - 1));
  };

  const handleDismiss = async (eventId: string) => {
    await realtimeApi.dismiss(eventId);
    setEvents((prev) => prev.filter((e) => e.id !== eventId));
    setUnreadCount((prev) => Math.max(0, prev - 1));
  };

  const formatTime = (iso: string) => {
    const d = new Date(iso);
    const now = new Date();
    const diffMs = now.getTime() - d.getTime();
    const diffMin = Math.floor(diffMs / 60000);
    if (diffMin < 1) return 'Just now';
    if (diffMin < 60) return `${diffMin}m ago`;
    const diffHr = Math.floor(diffMin / 60);
    if (diffHr < 24) return `${diffHr}h ago`;
    return d.toLocaleDateString();
  };

  const filteredEvents =
    activeTab === 'unread'
      ? events.filter((e) => !e.readAt)
      : activeTab === 'critical'
        ? events.filter((e) => e.severity === 'critical' || e.severity === 'urgent')
        : activeTab === 'acknowledged'
          ? events.filter((e) => e.acknowledgedAt)
          : events;

  const categories = ['all', 'appointment', 'clinical', 'pharmacy', 'billing', 'admin', 'system'];

  return (
    <div className="oc-page">
      {/* Header */}
      <header className="oc-header">
        <div className="oc-header__title">
          <BellRing size={24} />
          <div>
            <h1>Operations Center</h1>
            <p className="oc-header__subtitle">
              Real-time hospital events and notifications
            </p>
          </div>
        </div>
        <div className="oc-header__controls">
          <div className={`oc-connection ${connected ? 'oc-connection--on' : 'oc-connection--off'}`}>
            {connected ? <Wifi size={14} /> : <WifiOff size={14} />}
            <span>{connected ? 'Live' : 'Reconnecting...'}</span>
          </div>
          {unreadCount > 0 && (
            <Button onClick={handleMarkAllRead} variant="ghost" size="sm">
              <CheckCircle size={16} />
              Mark all read
            </Button>
          )}
          <Button onClick={() => { fetchEvents(); fetchCounts(); }} variant="ghost" size="sm">
            <RefreshCw size={16} />
          </Button>
        </div>
      </header>

      {/* Severity badges */}
      <div className="oc-severity-row">
        {Object.entries(SEVERITY_CONFIG).map(([key, config]) => {
          const Icon = config.icon;
          const count = severityCounts[key] ?? 0;
          return (
            <button
              key={key}
              className={`oc-severity-badge ${activeTab === 'critical' && (key === 'critical' || key === 'urgent') ? 'oc-severity-badge--active' : ''}`}
              style={{ '--badge-color': config.color, '--badge-bg': config.bg } as React.CSSProperties}
              onClick={() => setActiveTab(key === 'critical' || key === 'urgent' ? 'critical' : 'all')}
            >
              <Icon size={16} />
              <span>{config.label}</span>
              {count > 0 && <span className="oc-severity-badge__count">{count}</span>}
            </button>
          );
        })}
        <div className="oc-severity-badge oc-severity-badge--unread" onClick={() => setActiveTab(activeTab === 'unread' ? 'all' : 'unread')}>
          <EyeOff size={16} />
          <span>Unread</span>
          {unreadCount > 0 && <span className="oc-severity-badge__count">{unreadCount}</span>}
        </div>
      </div>

      {/* Category filters */}
      <div className="oc-categories">
        {categories.map((cat) => (
          <button
            key={cat}
            className={`oc-category-pill ${categoryFilter === cat ? 'oc-category-pill--active' : ''}`}
            onClick={() => setCategoryFilter(cat)}
          >
            {cat === 'all' ? 'All' : CATEGORY_LABELS[cat] ?? cat}
          </button>
        ))}
      </div>

      {/* Tabs */}
      <div className="oc-tabs">
        {(['all', 'unread', 'critical', 'acknowledged'] as Tab[]).map((tab) => (
          <button
            key={tab}
            className={`oc-tab ${activeTab === tab ? 'oc-tab--active' : ''}`}
            onClick={() => setActiveTab(tab)}
          >
            {tab === 'all' && 'All Events'}
            {tab === 'unread' && 'Unread'}
            {tab === 'critical' && 'Critical'}
            {tab === 'acknowledged' && 'Acknowledged'}
          </button>
        ))}
      </div>

      {/* Event list */}
      <div className="oc-content">
        {loading ? (
          <div className="oc-loading">
            <div className="oc-loading__spinner" />
            <p>Loading events...</p>
          </div>
        ) : filteredEvents.length === 0 ? (
          <EmptyState
            title="No Events"
            body={activeTab === 'unread' ? 'All caught up! No unread events.' : 'No events match the current filters.'}
          />
        ) : (
          <div className="oc-event-list">
            {filteredEvents.map((event) => {
              const sev = SEVERITY_CONFIG[event.severity] ?? SEVERITY_CONFIG.info;
              const SevIcon = sev.icon;
              const isUnread = !event.readAt;
              const isAcknowledged = !!event.acknowledgedAt;

              return (
                <div
                  key={event.id}
                  className={`oc-event-row ${isUnread ? 'oc-event-row--unread' : ''} ${event.severity === 'critical' ? 'oc-event-row--critical' : ''}`}
                  onClick={() => setSelectedEvent(event)}
                >
                  <div className="oc-event-row__severity" style={{ background: sev.bg, color: sev.color }}>
                    <SevIcon size={18} />
                  </div>
                  <div className="oc-event-row__content">
                    <div className="oc-event-row__header">
                      <span className="oc-event-row__title">{event.title}</span>
                      <span className="oc-event-row__time">{formatTime(event.createdAt)}</span>
                    </div>
                    {event.message && (
                      <p className="oc-event-row__message">{event.message}</p>
                    )}
                    <div className="oc-event-row__meta">
                      <span className="oc-event-row__category">{CATEGORY_LABELS[event.category] ?? event.category}</span>
                      <span className="oc-event-row__type">{event.eventType}</span>
                      {event.acknowledgementRequired && !isAcknowledged && (
                        <span className="oc-event-row__ack-required">
                          <Shield size={12} /> ACK Required
                        </span>
                      )}
                      {isAcknowledged && (
                        <span className="oc-event-row__ack-done">
                          <CheckCircle size={12} /> Acknowledged
                        </span>
                      )}
                    </div>
                  </div>
                  <div className="oc-event-row__actions" onClick={(e) => e.stopPropagation()}>
                    {isUnread && (
                      <button
                        className="oc-action-btn"
                        title="Mark as read"
                        onClick={() => handleMarkRead([event.id])}
                      >
                        <Eye size={16} />
                      </button>
                    )}
                    {event.acknowledgementRequired && !isAcknowledged && (
                      <button
                        className="oc-action-btn oc-action-btn--ack"
                        title="Acknowledge"
                        onClick={() => handleAcknowledge(event.id)}
                      >
                        <CheckCircle size={16} />
                      </button>
                    )}
                    <button
                      className="oc-action-btn"
                      title="Dismiss"
                      onClick={() => handleDismiss(event.id)}
                    >
                      <X size={16} />
                    </button>
                  </div>
                </div>
              );
            })}
          </div>
        )}
      </div>

      {/* Detail panel */}
      {selectedEvent && (
        <div className="oc-detail-overlay" onClick={() => setSelectedEvent(null)}>
          <div className="oc-detail-panel" onClick={(e) => e.stopPropagation()}>
            <div className="oc-detail-panel__header">
              <h2>{selectedEvent.title}</h2>
              <button className="oc-detail-panel__close" onClick={() => setSelectedEvent(null)}>
                <X size={20} />
              </button>
            </div>
            <div className="oc-detail-panel__body">
              <div className="oc-detail-row">
                <span className="oc-detail-label">Type</span>
                <span className="oc-detail-value">{selectedEvent.eventType}</span>
              </div>
              <div className="oc-detail-row">
                <span className="oc-detail-label">Severity</span>
                <span className="oc-detail-value" style={{ color: SEVERITY_CONFIG[selectedEvent.severity]?.color }}>
                  {SEVERITY_CONFIG[selectedEvent.severity]?.label ?? selectedEvent.severity}
                </span>
              </div>
              <div className="oc-detail-row">
                <span className="oc-detail-label">Category</span>
                <span className="oc-detail-value">{CATEGORY_LABELS[selectedEvent.category] ?? selectedEvent.category}</span>
              </div>
              <div className="oc-detail-row">
                <span className="oc-detail-label">Channel</span>
                <span className="oc-detail-value">{selectedEvent.channel}</span>
              </div>
              <div className="oc-detail-row">
                <span className="oc-detail-label">Time</span>
                <span className="oc-detail-value">{new Date(selectedEvent.createdAt).toLocaleString()}</span>
              </div>
              {selectedEvent.message && (
                <div className="oc-detail-row oc-detail-row--full">
                  <span className="oc-detail-label">Message</span>
                  <p className="oc-detail-message">{selectedEvent.message}</p>
                </div>
              )}
              <div className="oc-detail-row">
                <span className="oc-detail-label">Status</span>
                <span className="oc-detail-value">
                  {selectedEvent.readAt ? 'Read' : 'Unread'}
                  {selectedEvent.acknowledgedAt ? ' · Acknowledged' : ''}
                </span>
              </div>
              {selectedEvent.actionUrl && (
                <div className="oc-detail-row">
                  <span className="oc-detail-label">Action</span>
                  <a href={selectedEvent.actionUrl} className="oc-detail-link">Open →</a>
                </div>
              )}
            </div>
            <div className="oc-detail-panel__actions">
              {!selectedEvent.readAt && (
                <Button onClick={() => { handleMarkRead([selectedEvent.id]); setSelectedEvent(null); }} size="sm">
                  <Eye size={16} /> Mark Read
                </Button>
              )}
              {selectedEvent.acknowledgementRequired && !selectedEvent.acknowledgedAt && (
                <Button onClick={() => { handleAcknowledge(selectedEvent.id); setSelectedEvent(null); }} size="sm">
                  <CheckCircle size={16} /> Acknowledge
                </Button>
              )}
              <Button onClick={() => { handleDismiss(selectedEvent.id); setSelectedEvent(null); }} variant="ghost" size="sm">
                Dismiss
              </Button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
