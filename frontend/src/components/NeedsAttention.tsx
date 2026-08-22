/**
 * Needs Attention — the enterprise alert/task workspace.
 *
 * Every role sees only the alerts relevant to their responsibilities.
 * Items are actionable, owned, prioritized, and timestamped.
 *
 * Backend authorization remains authoritative.
 * This is frontend-only visibility gating.
 */

import { Link } from 'react-router-dom';
import { useAccess } from '../auth/useAccess';
import {
  AlertTriangle,
  Clock,
  FileText,
  FlaskConical,
  Pill,
  WalletCards,
  Users,
  Bell,
  CheckCircle,
  ChevronRight,
  Activity,
} from 'lucide-react';

interface AttentionItem {
  id: string;
  icon: React.ReactNode;
  title: string;
  description: string;
  severity: 'critical' | 'warning' | 'info';
  module: string;
  actionTo: string;
  timestamp: string;
  badge?: string;
}

function severityColor(severity: string) {
  switch (severity) {
    case 'critical': return 'attention--critical';
    case 'warning': return 'attention--warning';
    default: return 'attention--info';
  }
}

function timeAgo(iso: string) {
  if (!iso) return '';
  const diff = Date.now() - new Date(iso).getTime();
  const mins = Math.floor(diff / 60000);
  if (mins < 1) return 'just now';
  if (mins < 60) return `${mins}m ago`;
  const hrs = Math.floor(mins / 60);
  if (hrs < 24) return `${hrs}h ago`;
  return `${Math.floor(hrs / 24)}d ago`;
}

interface NeedsAttentionProps {
  metrics?: {
    criticalValues?: number;
    pendingLabOrders?: number;
    lowStockItems?: number;
    expiringItems?: number;
    pendingStudies?: number;
    pendingReports?: number;
    erWaiting?: number;
    inQueue?: number;
    outstandingAmount?: number;
    admissionsToday?: number;
    dischargesToday?: number;
    unreadNotifications?: number;
  } | null;
}

/**
 * Generate role-aware attention items from real backend metrics.
 */
function generateAttentionItems(
  access: ReturnType<typeof useAccess>,
  metrics: NeedsAttentionProps['metrics'],
): AttentionItem[] {
  const items: AttentionItem[] = [];

  // Critical lab values — relevant to doctors, nurses, lab
  if (metrics?.criticalValues && metrics.criticalValues > 0) {
    items.push({
      id: 'critical-values',
      icon: <AlertTriangle size={16} />,
      title: `${metrics.criticalValues} critical lab value${metrics.criticalValues !== 1 ? 's' : ''}`,
      description: 'Requires immediate clinical attention',
      severity: 'critical',
      module: 'laboratory',
      actionTo: '/laboratory/orders',
      timestamp: new Date().toISOString(),
      badge: 'CRITICAL',
    });
  }

  // Pending lab orders — relevant to lab technicians
  if (metrics?.pendingLabOrders && metrics.pendingLabOrders > 0 && access.isLab()) {
    items.push({
      id: 'pending-lab',
      icon: <FlaskConical size={16} />,
      title: `${metrics.pendingLabOrders} pending lab order${metrics.pendingLabOrders !== 1 ? 's' : ''}`,
      description: 'Orders awaiting processing',
      severity: 'warning',
      module: 'laboratory',
      actionTo: '/laboratory/orders',
      timestamp: new Date().toISOString(),
    });
  }

  // Pending radiology — relevant to radiology staff
  if (metrics?.pendingStudies && metrics.pendingStudies > 0) {
    items.push({
      id: 'pending-rad',
      icon: <Activity size={16} />,
      title: `${metrics.pendingStudies} pending imaging stud${metrics.pendingStudies !== 1 ? 'ies' : 'y'}`,
      description: 'Studies awaiting processing or report',
      severity: 'warning',
      module: 'radiology',
      actionTo: '/radiology',
      timestamp: new Date().toISOString(),
    });
  }

  // Pending radiology reports
  if (metrics?.pendingReports && metrics.pendingReports > 0) {
    items.push({
      id: 'pending-reports',
      icon: <FileText size={16} />,
      title: `${metrics.pendingReports} report${metrics.pendingReports !== 1 ? 's' : ''} pending`,
      description: 'Completed studies awaiting report',
      severity: 'warning',
      module: 'radiology',
      actionTo: '/radiology',
      timestamp: new Date().toISOString(),
    });
  }

  // Low stock medications — relevant to pharmacists and admins
  if (metrics?.lowStockItems && metrics.lowStockItems > 0 && (access.isPharmacy() || access.isHospitalAdmin())) {
    items.push({
      id: 'low-stock',
      icon: <Pill size={16} />,
      title: `${metrics.lowStockItems} medication${metrics.lowStockItems !== 1 ? 's' : ''} below reorder`,
      description: 'Stock levels need attention',
      severity: 'warning',
      module: 'pharmacy',
      actionTo: '/pharmacy/inventory',
      timestamp: new Date().toISOString(),
    });
  }

  // Expiring medications
  if (metrics?.expiringItems && metrics.expiringItems > 0 && (access.isPharmacy() || access.isHospitalAdmin())) {
    items.push({
      id: 'expiring',
      icon: <Clock size={16} />,
      title: `${metrics.expiringItems} batch${metrics.expiringItems !== 1 ? 'es' : ''} expiring soon`,
      description: 'Within 3 months — review for disposal',
      severity: 'info',
      module: 'pharmacy',
      actionTo: '/pharmacy/inventory',
      timestamp: new Date().toISOString(),
    });
  }

  // ER waiting — relevant to hospital staff
  if (metrics?.erWaiting && metrics.erWaiting > 0 && access.isHospitalAdmin()) {
    items.push({
      id: 'er-waiting',
      icon: <Users size={16} />,
      title: `${metrics.erWaiting} patient${metrics.erWaiting !== 1 ? 's' : ''} in ER waiting`,
      description: 'Emergency department queue pressure',
      severity: 'warning',
      module: 'hospital',
      actionTo: '/emergency',
      timestamp: new Date().toISOString(),
    });
  }

  // Patients in queue — relevant to doctors and receptionists
  if (metrics?.inQueue && metrics.inQueue > 0 && (access.isClinical() || access.hasRole('receptionist'))) {
    items.push({
      id: 'queue',
      icon: <Clock size={16} />,
      title: `${metrics.inQueue} patient${metrics.inQueue !== 1 ? 's' : ''} in queue`,
      description: 'Waiting for consultation',
      severity: 'info',
      module: 'clinical',
      actionTo: '/clinical/queue',
      timestamp: new Date().toISOString(),
    });
  }

  // Outstanding invoices — relevant to billing
  if (metrics?.outstandingAmount && metrics.outstandingAmount > 0 && access.isFinance()) {
    const amount = `NPR ${(metrics.outstandingAmount / 100).toLocaleString('en-US', { maximumFractionDigits: 0 })}`;
    items.push({
      id: 'outstanding',
      icon: <WalletCards size={16} />,
      title: `${amount} outstanding`,
      description: 'Unpaid invoices requiring follow-up',
      severity: 'info',
      module: 'finance',
      actionTo: '/finance/billing',
      timestamp: new Date().toISOString(),
    });
  }

  // Discharges pending — relevant to hospital/nursing
  if (metrics?.dischargesToday && metrics.dischargesToday > 0 && (access.isHospitalAdmin() || access.hasRole('nurse'))) {
    items.push({
      id: 'discharges',
      icon: <CheckCircle size={16} />,
      title: `${metrics.dischargesToday} discharge${metrics.dischargesToday !== 1 ? 's' : ''} today`,
      description: 'Completed — verify discharge documentation',
      severity: 'info',
      module: 'hospital',
      actionTo: '/hospital/ipd',
      timestamp: new Date().toISOString(),
    });
  }

  return items;
}

export function NeedsAttention({ metrics }: NeedsAttentionProps) {
  const access = useAccess();
  const items = generateAttentionItems(access, metrics);

  if (items.length === 0) {
    return (
      <div className="needs-attention needs-attention--empty">
        <CheckCircle size={20} strokeWidth={1.5} />
        <div>
          <p className="needs-attention__title">All clear</p>
          <p className="needs-attention__desc">No items require your attention right now.</p>
        </div>
      </div>
    );
  }

  return (
    <div className="needs-attention">
      <div className="needs-attention__header">
        <h3 className="needs-attention__heading">
          <Bell size={16} />
          Needs attention
          <span className="needs-attention__count">{items.length}</span>
        </h3>
      </div>
      <div className="needs-attention__list">
        {items.map((item) => (
          <Link
            key={item.id}
            to={item.actionTo}
            className={`needs-attention__item ${severityColor(item.severity)}`}
          >
            <div className="needs-attention__icon">
              {item.icon}
            </div>
            <div className="needs-attention__content">
              <span className="needs-attention__item-title">{item.title}</span>
              <span className="needs-attention__item-desc">{item.description}</span>
            </div>
            <div className="needs-attention__meta">
              {item.badge && (
                <span className={`needs-attention__badge needs-attention__badge--${item.severity}`}>
                  {item.badge}
                </span>
              )}
              <span className="needs-attention__time">{timeAgo(item.timestamp)}</span>
            </div>
            <ChevronRight size={14} className="needs-attention__chevron" />
          </Link>
        ))}
      </div>
    </div>
  );
}
