import { type ReactNode, type CSSProperties, useEffect, useRef, useState } from 'react';
import {
  TrendingUp,
  TrendingDown,
  Minus,
  type LucideIcon,
} from 'lucide-react';

/* ──────────────────────────────────────────────────────────────────── KPI ── */

interface KpiCardProps {
  label: string;
  value: string | number;
  icon?: LucideIcon;
  iconColor?: 'blue' | 'green' | 'red' | 'amber' | 'gray';
  trend?: { value: string; direction: 'up' | 'down' | 'neutral' };
  link?: string;
  className?: string;
}

export function KpiCard({ label, value, icon: Icon, iconColor = 'blue', trend, className = '' }: KpiCardProps) {
  const [changed, setChanged] = useState(false);
  const prevValue = useRef(value);

  useEffect(() => {
    if (prevValue.current !== value) {
      setChanged(true);
      prevValue.current = value;
      const t = setTimeout(() => setChanged(false), 350);
      return () => clearTimeout(t);
    }
  }, [value]);

  const TrendIcon = trend?.direction === 'up' ? TrendingUp : trend?.direction === 'down' ? TrendingDown : Minus;

  return (
    <div className={`kpi-card ${className}`}>
      <div className="kpi-card__header">
        <span className="kpi-card__label">{label}</span>
        {Icon && (
          <span className={`kpi-card__icon kpi-card__icon--${iconColor}`}>
            <Icon size={16} />
          </span>
        )}
      </div>
      <span className={`kpi-card__value kpi-number ${changed ? 'kpi-number--changed' : ''}`}>
        {typeof value === 'number' ? value.toLocaleString() : value}
      </span>
      {trend && (
        <span className={`kpi-card__trend kpi-card__trend--${trend.direction}`}>
          <TrendIcon size={12} />
          {trend.value}
        </span>
      )}
    </div>
  );
}

/* ─────────────────────────────────────────────────────────────── Chart Card ── */

interface ChartCardProps {
  title: string;
  subtitle?: string;
  children: ReactNode;
  actions?: ReactNode;
  className?: string;
  style?: CSSProperties;
}

export function ChartCard({ title, subtitle, children, actions, className = '', style }: ChartCardProps) {
  return (
    <div className={`chart-card ${className}`} style={style}>
      <div className="chart-card__header">
        <div>
          <div className="chart-card__title">{title}</div>
          {subtitle && <div className="chart-card__subtitle">{subtitle}</div>}
        </div>
        {actions && <div className="chart-card__actions">{actions}</div>}
      </div>
      <div className="chart-card__body">{children}</div>
    </div>
  );
}

/* ───────────────────────────────────────────────────────────── Table Card ── */

interface TableColumn<T> {
  key: string;
  label: string;
  render?: (row: T) => ReactNode;
  align?: 'left' | 'right' | 'center';
}

interface TableCardProps<T> {
  title: string;
  columns: TableColumn<T>[];
  data: T[];
  maxRows?: number;
  emptyMessage?: string;
  className?: string;
}

export function TableCard<T extends Record<string, unknown>>({
  title,
  columns,
  data,
  maxRows = 10,
  emptyMessage = 'No data available',
  className = '',
}: TableCardProps<T>) {
  const visible = data.slice(0, maxRows);

  return (
    <div className={`table-card ${className}`}>
      <div className="table-card__header">
        <span className="table-card__title">{title}</span>
        {data.length > maxRows && (
          <span style={{ fontSize: 'var(--text-caption)', color: 'var(--text-tertiary)' }}>
            Showing {maxRows} of {data.length}
          </span>
        )}
      </div>
      <div className="table-card__body">
        {visible.length === 0 ? (
          <div className="dashboard-empty" style={{ padding: 'var(--space-8)' }}>
            <p className="dashboard-empty__title">{emptyMessage}</p>
          </div>
        ) : (
          <table>
            <thead>
              <tr>
                {columns.map((col) => (
                  <th key={col.key} style={{ textAlign: col.align ?? 'left' }}>
                    {col.label}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {visible.map((row, i) => (
                <tr key={i}>
                  {columns.map((col) => (
                    <td key={col.key} style={{ textAlign: col.align ?? 'left' }}>
                      {col.render ? col.render(row) : String(row[col.key] ?? '')}
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </div>
  );
}

/* ────────────────────────────────────────────────────────────── Alert Card ── */

interface AlertCardProps {
  type: 'info' | 'warning' | 'danger' | 'success';
  title: string;
  message?: string;
  icon?: LucideIcon;
  className?: string;
}

export function AlertCard({ type, title, message, icon: Icon, className = '' }: AlertCardProps) {
  const iconColor = {
    info: 'var(--blue-600)',
    warning: 'var(--amber-600)',
    danger: 'var(--red-600)',
    success: 'var(--green-600)',
  }[type];

  return (
    <div className={`alert-card alert-card--${type} ${className}`}>
      {Icon && (
        <span className="alert-card__icon" style={{ color: iconColor }}>
          <Icon size={16} />
        </span>
      )}
      <div className="alert-card__content">
        <div className="alert-card__title">{title}</div>
        {message && <div className="alert-card__message">{message}</div>}
      </div>
    </div>
  );
}

/* ──────────────────────────────────────────────────────────── Filter Bar ── */

interface FilterOption {
  value: string;
  label: string;
}

interface FilterBarProps {
  dateRange?: { start: string; end: string; onChange: (start: string, end: string) => void };
  facilityOptions?: FilterOption[];
  facility?: string;
  onFacilityChange?: (v: string) => void;
  departmentOptions?: FilterOption[];
  department?: string;
  onDepartmentChange?: (v: string) => void;
  children?: ReactNode;
  className?: string;
}

export function FilterBar({
  dateRange,
  facilityOptions,
  facility,
  onFacilityChange,
  departmentOptions,
  department,
  onDepartmentChange,
  children,
  className = '',
}: FilterBarProps) {
  return (
    <div className={`filter-bar ${className}`}>
      {dateRange && (
        <div className="filter-bar__date">
          <span className="filter-bar__label">From</span>
          <input
            type="date"
            value={dateRange.start}
            onChange={(e) => dateRange.onChange(e.target.value, dateRange.end)}
          />
          <span className="filter-bar__label">To</span>
          <input
            type="date"
            value={dateRange.end}
            onChange={(e) => dateRange.onChange(dateRange.start, e.target.value)}
          />
        </div>
      )}

      {facilityOptions && facilityOptions.length > 1 && (
        <>
          <div className="filter-bar__divider" />
          <div className="filter-bar__group">
            <span className="filter-bar__label">Facility</span>
            <select
              className="filter-bar__select"
              value={facility ?? ''}
              onChange={(e) => onFacilityChange?.(e.target.value)}
            >
              {facilityOptions.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </select>
          </div>
        </>
      )}

      {departmentOptions && departmentOptions.length > 1 && (
        <>
          <div className="filter-bar__divider" />
          <div className="filter-bar__group">
            <span className="filter-bar__label">Department</span>
            <select
              className="filter-bar__select"
              value={department ?? ''}
              onChange={(e) => onDepartmentChange?.(e.target.value)}
            >
              <option value="">All Departments</option>
              {departmentOptions.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </select>
          </div>
        </>
      )}

      {children}
    </div>
  );
}

/* ────────────────────────────────────────────────────────── Occupancy Bar ── */

interface OccupancyBarProps {
  occupied: number;
  available: number;
  cleaning?: number;
  maintenance?: number;
  height?: number;
  className?: string;
}

export function OccupancyBar({ occupied, available, cleaning = 0, maintenance = 0, height = 8, className = '' }: OccupancyBarProps) {
  const total = occupied + available + cleaning + maintenance;
  if (total === 0) return <div className={`occupancy-bar ${className}`} style={{ height }} />;

  return (
    <div className={`occupancy-bar ${className}`} style={{ height }}>
      {occupied > 0 && (
        <div
          className="occupancy-bar__segment occupancy-bar__segment--occupied"
          style={{ width: `${(occupied / total) * 100}%` }}
        />
      )}
      {cleaning > 0 && (
        <div
          className="occupancy-bar__segment occupancy-bar__segment--cleaning"
          style={{ width: `${(cleaning / total) * 100}%` }}
        />
      )}
      {maintenance > 0 && (
        <div
          className="occupancy-bar__segment occupancy-bar__segment--maintenance"
          style={{ width: `${(maintenance / total) * 100}%` }}
        />
      )}
      {available > 0 && (
        <div
          className="occupancy-bar__segment occupancy-bar__segment--available"
          style={{ width: `${(available / total) * 100}%` }}
        />
      )}
    </div>
  );
}

/* ──────────────────────────────────────────────────────── Activity Feed ── */

interface ActivityItem {
  id: string;
  text: string;
  time: string;
  color?: 'blue' | 'green' | 'amber' | 'red';
}

interface ActivityFeedProps {
  items: ActivityItem[];
  maxItems?: number;
  className?: string;
}

export function ActivityFeed({ items, maxItems = 8, className = '' }: ActivityFeedProps) {
  const visible = items.slice(0, maxItems);

  return (
    <div className={`activity-feed ${className}`}>
      {visible.length === 0 ? (
        <div className="dashboard-empty" style={{ padding: 'var(--space-6)' }}>
          <p className="dashboard-empty__title">No recent activity</p>
        </div>
      ) : (
        visible.map((item) => (
          <div key={item.id} className="activity-feed__item">
            <div className={`activity-feed__dot activity-feed__dot--${item.color ?? 'blue'}`} />
            <span className="activity-feed__text">{item.text}</span>
            <span className="activity-feed__time">{item.time}</span>
          </div>
        ))
      )}
    </div>
  );
}

/* ─────────────────────────────────────────────────────────── Skeleton ── */

export function DashboardSkeleton({ type = 'kpi' }: { type?: 'kpi' | 'chart' | 'rows' }) {
  if (type === 'chart') return <div className="skeleton skeleton--chart" />;
  if (type === 'rows') {
    return (
      <div>
        {Array.from({ length: 5 }).map((_, i) => (
          <div key={i} className="skeleton skeleton--row" />
        ))}
      </div>
    );
  }
  return <div className="skeleton skeleton--kpi" />;
}

/* ───────────────────────────────────────────────────── Dashboard Section ── */

interface DashboardSectionProps {
  title: string;
  subtitle?: string;
  actions?: ReactNode;
  children: ReactNode;
}

export function DashboardSection({ title, subtitle, actions, children }: DashboardSectionProps) {
  return (
    <div className="dashboard-section">
      <div className="dashboard-section__header">
        <div>
          <h2 className="dashboard-section__title">{title}</h2>
          {subtitle && <p className="dashboard-section__subtitle">{subtitle}</p>}
        </div>
        {actions}
      </div>
      {children}
    </div>
  );
}

/* ──────────────────────────────────────────────────── Status Badge ── */

export function StatusBadge({ status, className = '' }: { status: string; className?: string }) {
  const colorMap: Record<string, string> = {
    active: 'var(--green-600)',
    completed: 'var(--green-600)',
    pending: 'var(--amber-600)',
    booked: 'var(--blue-600)',
    ordered: 'var(--blue-600)',
    draft: 'var(--gray-500)',
    cancelled: 'var(--red-600)',
    no_show: 'var(--red-600)',
    critical: 'var(--red-600)',
    low_stock: 'var(--amber-600)',
    issued: 'var(--blue-600)',
    paid: 'var(--green-600)',
    overdue: 'var(--red-600)',
    expired: 'var(--red-600)',
    available: 'var(--green-600)',
    occupied: 'var(--blue-600)',
    cleaning: 'var(--amber-600)',
    maintenance: 'var(--gray-500)',
    checked_in: 'var(--green-600)',
    in_consultation: 'var(--blue-600)',
    waiting: 'var(--amber-600)',
    discharged: 'var(--green-600)',
    open: 'var(--blue-600)',
    in_progress: 'var(--blue-600)',
    signed: 'var(--green-600)',
    dispensed: 'var(--green-600)',
    reported: 'var(--green-600)',
    verified: 'var(--green-600)',
  };

  const color = colorMap[status] ?? 'var(--gray-500)';
  const label = status.replace(/_/g, ' ');

  return (
    <span
      className={`status-dot status-dot--${status === 'critical' ? 'critical' : status.includes('cancel') || status.includes('no_show') ? 'critical' : status.includes('pending') || status.includes('waiting') || status.includes('draft') ? 'warning' : 'active'} ${className}`}
    >
      <span style={{ color }}>
        {label.charAt(0).toUpperCase() + label.slice(1)}
      </span>
    </span>
  );
}
