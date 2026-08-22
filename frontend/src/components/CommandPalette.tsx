import { useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useI18n } from '../i18n/I18nProvider';
import './command-palette.css';

interface CommandItem {
  id: string;
  label: string;
  group: string;
  shortcut?: string;
  action: () => void;
}

/**
 * Global search command palette triggered by Cmd+K / Ctrl+K.
 *
 * Provides keyboard-navigable access to every major page, plus
 * quick-action items for common workflows.
 */
export function CommandPalette() {
  const { t } = useI18n();
  const navigate = useNavigate();
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');
  const [selectedIndex, setSelectedIndex] = useState(0);
  const inputRef = useRef<HTMLInputElement>(null);
  const listRef = useRef<HTMLDivElement>(null);

  /* ── command registry ── */
  const commands: CommandItem[] = useMemo(() => [
    // Navigation (module-first routes)
    { id: 'nav:dashboard', label: t('nav.dashboard'), group: 'Navigate', action: () => navigate('/') },
    { id: 'nav:hospital', label: t('module.hospital'), group: 'Modules', action: () => navigate('/hospital') },
    { id: 'nav:clinical', label: t('module.clinical'), group: 'Modules', action: () => navigate('/clinical') },
    { id: 'nav:pharmacy', label: t('module.pharmacy'), group: 'Modules', action: () => navigate('/pharmacy') },
    { id: 'nav:laboratory', label: t('module.laboratory'), group: 'Modules', action: () => navigate('/laboratory') },
    { id: 'nav:radiology', label: t('module.radiology'), group: 'Modules', action: () => navigate('/radiology') },
    { id: 'nav:finance', label: t('module.finance'), group: 'Modules', action: () => navigate('/finance') },
    { id: 'nav:procurement', label: t('module.procurement'), group: 'Modules', action: () => navigate('/procurement') },
    { id: 'nav:reports', label: t('module.reports'), group: 'Modules', action: () => navigate('/reports') },
    { id: 'nav:admin', label: t('module.administration'), group: 'Modules', action: () => navigate('/admin') },

    // Direct actions
    { id: 'nav:patients', label: t('nav.patients'), group: 'Clinical', action: () => navigate('/clinical/patients') },
    { id: 'nav:patient-new', label: 'Register Patient', group: 'Clinical', shortcut: 'R', action: () => navigate('/clinical/patients/new') },
    { id: 'nav:appointments', label: t('nav.appointments'), group: 'Clinical', action: () => navigate('/clinical/appointments') },
    { id: 'nav:queue', label: t('nav.queue'), group: 'Clinical', action: () => navigate('/clinical/queue') },
    { id: 'nav:billing', label: t('nav.billing'), group: 'Finance', action: () => navigate('/finance/billing') },
    { id: 'nav:emergency', label: t('nav.emergency'), group: 'Hospital', action: () => navigate('/emergency') },
    { id: 'nav:portal', label: t('nav.portal'), group: 'Navigate', action: () => navigate('/portal') },

    // Quick actions
    { id: 'act:new-patient', label: 'Register Patient', group: 'Quick Action', action: () => navigate('/clinical/patients/new') },
    { id: 'act:new-appointment', label: 'Book Appointment', group: 'Quick Action', action: () => navigate('/clinical/appointments') },
    { id: 'act:new-referral', label: 'Create Referral', group: 'Quick Action', action: () => navigate('/clinical/referrals') },
    { id: 'act:new-invoice', label: 'Create Invoice', group: 'Quick Action', action: () => navigate('/finance/billing') },
  ], [t, navigate]);

  /* ── filter ── */
  const filtered = useMemo(() => {
    if (!query) return commands;
    const q = query.toLowerCase();
    return commands.filter(
      (c) => c.label.toLowerCase().includes(q) || c.group.toLowerCase().includes(q),
    );
  }, [query, commands]);

  /* ── keyboard shortcut ── */
  useEffect(() => {
    const handler = (e: KeyboardEvent) => {
      if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
        e.preventDefault();
        setOpen((prev) => !prev);
        setQuery('');
        setSelectedIndex(0);
      }
      if (e.key === 'Escape' && open) {
        setOpen(false);
      }
    };
    document.addEventListener('keydown', handler);
    return () => document.removeEventListener('keydown', handler);
  }, [open]);

  /* ── focus input when opened ── */
  useEffect(() => {
    if (open) {
      requestAnimationFrame(() => inputRef.current?.focus());
    }
  }, [open]);

  /* ── keyboard navigation ── */
  const handleKeyDown = (e: React.KeyboardEvent) => {
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      setSelectedIndex((i) => Math.min(i + 1, filtered.length - 1));
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      setSelectedIndex((i) => Math.max(i - 1, 0));
    } else if (e.key === 'Enter' && filtered[selectedIndex]) {
      e.preventDefault();
      filtered[selectedIndex].action();
      setOpen(false);
    }
  };

  /* ── scroll selected into view ── */
  useEffect(() => {
    const el = listRef.current?.children[selectedIndex] as HTMLElement | undefined;
    el?.scrollIntoView({ block: 'nearest' });
  }, [selectedIndex]);

  /* ── group items ── */
  const grouped = useMemo(() => {
    const groups: Record<string, CommandItem[]> = {};
    filtered.forEach((item) => {
      if (!groups[item.group]) groups[item.group] = [];
      groups[item.group].push(item);
    });
    return groups;
  }, [filtered]);

  if (!open) return null;

  return (
    <div className="cmd-backdrop" onClick={() => setOpen(false)}>
      <div className="cmd-palette" role="dialog" aria-modal="true" aria-label="Global search" onClick={(e) => e.stopPropagation()}>
        <div className="cmd-input-wrap">
          <svg className="cmd-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
            <circle cx="11" cy="11" r="8" />
            <path d="m21 21-4.35-4.35" />
          </svg>
          <input
            ref={inputRef}
            className="cmd-input"
            placeholder="Search pages, actions…"
            value={query}
            onChange={(e) => { setQuery(e.target.value); setSelectedIndex(0); }}
            onKeyDown={handleKeyDown}
            autoComplete="off"
            spellCheck={false}
          />
          <kbd className="cmd-kbd">ESC</kbd>
        </div>
        <div className="cmd-list" ref={listRef}>
          {filtered.length === 0 ? (
            <div className="cmd-empty">No results found</div>
          ) : (
            Object.entries(grouped).map(([group, items]) => (
              <div key={group} className="cmd-group">
                <div className="cmd-group-label">{group}</div>
                {items.map((item) => {
                  const idx = filtered.indexOf(item);
                  return (
                    <button
                      key={item.id}
                      className={`cmd-item ${idx === selectedIndex ? 'cmd-item--selected' : ''}`}
                      onClick={() => { item.action(); setOpen(false); }}
                      onMouseEnter={() => setSelectedIndex(idx)}
                    >
                      <span className="cmd-item-label">{item.label}</span>
                      {item.shortcut && <kbd className="cmd-item-shortcut">{item.shortcut}</kbd>}
                    </button>
                  );
                })}
              </div>
            ))
          )}
        </div>
        <div className="cmd-footer">
          <span><kbd>↑↓</kbd> navigate</span>
          <span><kbd>↵</kbd> select</span>
          <span><kbd>esc</kbd> close</span>
        </div>
      </div>
    </div>
  );
}
