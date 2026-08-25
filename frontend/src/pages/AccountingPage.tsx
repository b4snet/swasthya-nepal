import { useCallback, useMemo, useState } from 'react';
import { useTenant } from '../context/TenantContext';
import { useFetch } from '../hooks/useFetch';
import { api } from '../api/client';
import { ApiError } from '../api/client';
import { Alert, Button, Card, EmptyState, Input } from '../components/ui';
const opt=(f?:string|null)=>({facilityId:f} as Record<string,unknown>);
const accountingApi={
  accounts:(f?:string|null)=>api.request<unknown[]>('/api/v1/accounts',opt(f)).catch(()=>[]),
  journals:(f?:string|null)=>api.request<unknown[]>('/api/v1/journals',opt(f)).catch(()=>[]),
  storeAccount:(p:Record<string,unknown>,f?:string|null)=>api.request<unknown>('/api/v1/accounts',{method:'POST',body:p,...opt(f)}),
};
interface Account{id:string;code:string;name:string;type:string;status:string;is_cash_account:boolean;is_bank_account:boolean;}
interface JournalEntry{id:string;entry_number:string;entry_date:string;description:string;status:string;source_type:string|null;}
const SC:Record<string,string>={draft:'#6b7280',reviewed:'#f59e0b',posted:'#10b981',reversed:'#ef4444'};
const TC:Record<string,string>={asset:'#3b82f6',liability:'#ef4444',equity:'#8b5cf6',revenue:'#10b981',expense:'#f59e0b'};
const AT=['asset','liability','equity','revenue','expense'];
export function AccountingPage(){
  const{selectedFacilityId:fac}=useTenant();
  const[error,setError]=useState<string|null>(null);
  const[tab,setTab]=useState<'accounts'|'journals'>('accounts');
  const[dlg,setDlg]=useState<string|null>(null);
  const[busy,setBusy]=useState(false);
  const[af,setAf]=useState({code:'',name:'',type:'asset',description:''});
  const accts=useFetch(()=>accountingApi.accounts(fac),[fac]);
  const journs=useFetch(()=>accountingApi.journals(fac),[fac]);
  const aa=useMemo(()=>(accts.data??[])as Account[],[accts.data]);
  const aj=useMemo(()=>(journs.data??[])as JournalEntry[],[journs.data]);
  const go=useCallback(async (fn:() => Promise<unknown>): Promise<unknown | null>=>{
    setBusy(true);setError(null);
    try { return await fn(); } catch (e: unknown) { setError(e instanceof ApiError ? e.message : "Failed"); return null; } finally { setBusy(false); }
  },[]);
  const mkAcct=useCallback(async(e:React.FormEvent)=>{
    e.preventDefault();if(!af.code||!af.name)return;
    await go(()=>accountingApi.storeAccount(af,fac));
    setDlg(null);setAf({code:'',name:'',type:'asset',description:''});accts.refresh();
  },[af,fac,go,accts]);
  return(<div className='page analytics-page'>
    <header className='page__head'>
      <div><h1 className='page__title'>Accounting</h1><p className='page__subtitle'>Chart of accounts, journal entries, trial balance</p></div>
      <div className='ai-actions'><Button variant='ghost' onClick={()=>{accts.refresh();journs.refresh();}}>Refresh</Button></div>
    </header>
    {error&&<Alert tone='danger'>{error}</Alert>}
    <div className='analytics-census'>
      <div className='analytics-census-card analytics-census-card--kpis'><span className='analytics-census-value'>{aa.length}</span><span className='analytics-census-label'>Accounts</span></div>
      <div className='analytics-census-card analytics-census-card--dashboards'><span className='analytics-census-value'>{aj.length}</span><span className='analytics-census-label'>Journal Entries</span></div>
      <div className='analytics-census-card analytics-census-card--operational'><span className='analytics-census-value' style={{color:'#6b7280'}}>{aj.filter(j=>j.status==='draft').length}</span><span className='analytics-census-label'>Draft</span></div>
      <div className='analytics-census-card analytics-census-card--clinical'><span className='analytics-census-value' style={{color:'#10b981'}}>{aj.filter(j=>j.status==='posted').length}</span><span className='analytics-census-label'>Posted</span></div>
    </div>
    <div className='analytics-tabs'>
      {(['accounts','journals'] as const).map(t=>(<button key={t} className={`analytics-tab ${tab===t?'analytics-tab--active':''}`} onClick={()=>setTab(t)}>{t==='accounts'?'Chart of Accounts':'Journal Entries'}</button>))}
    </div>
    {tab==='accounts'&&<Card className='analytics-section-card'>
      <div className='analytics-section-header'><h3>Chart of Accounts</h3><Button variant='primary' size='sm' onClick={()=>setDlg('new-account')}>+ Add Account</Button></div>
      {aa.length===0?<EmptyState title='No accounts' body='Create accounts to build your chart of accounts.'/>:
      <div className='analytics-table'>
        <div className='analytics-table-header'><span>Code</span><span>Name</span><span>Type</span><span>Status</span></div>
        {aa.map(a=><div key={a.id} className='analytics-table-row'><span className='analytics-mono'>{a.code}</span><span className='analytics-name'>{a.name}</span><span style={{color:TC[a.type]??'#6b7280',fontWeight:500}}>{a.type}</span><span>{a.status}</span></div>)}
      </div>}
    </Card>}
    {tab==='journals'&&<Card className='analytics-section-card'>
      <div className='analytics-section-header'><h3>Journal Entries</h3></div>
      {aj.length===0?<EmptyState title='No journal entries' body='Journal entries are created from financial events.'/>:
      <div className='analytics-table'>
        <div className='analytics-table-header'><span>Entry #</span><span>Date</span><span>Description</span><span>Status</span></div>
        {aj.map(j=><div key={j.id} className='analytics-table-row'><span className='analytics-mono'>{j.entry_number}</span><span>{j.entry_date}</span><span className='analytics-name'>{j.description}</span><span style={{color:SC[j.status]??'#6b7280',fontWeight:600}}>{j.status}</span></div>)}
      </div>}
    </Card>}
    {dlg==='new-account'&&<div className='dialog-overlay' onClick={()=>setDlg(null)}>
      <div className='dialog' onClick={e=>e.stopPropagation()}>
        <h3>Add Account</h3>
        <form onSubmit={mkAcct} style={{display:'flex',flexDirection:'column',gap:12,marginTop:12}}>
          <Input label='Account Code' value={af.code} onChange={e=>setAf(f=>({...f,code:e.target.value}))} placeholder='e.g. 1000' required/>
          <Input label='Account Name' value={af.name} onChange={e=>setAf(f=>({...f,name:e.target.value}))} placeholder='e.g. Cash' required/>
          <div style={{display:'flex',flexDirection:'column',gap:4}}>
            <label style={{fontSize:12,fontWeight:500,color:'#374151'}}>Type</label>
            <select value={af.type} onChange={e=>setAf(f=>({...f,type:e.target.value}))} style={{padding:'6px 10px',border:'1px solid #d1d5db',borderRadius:6,fontSize:14}}>
              {AT.map(t=><option key={t} value={t}>{t.charAt(0).toUpperCase()+t.slice(1)}</option>)}
            </select>
          </div>
          <div style={{display:'flex',justifyContent:'flex-end',gap:8,marginTop:8}}>
            <Button variant='ghost' type='button' onClick={()=>setDlg(null)}>Cancel</Button>
            <Button type='submit' loading={busy} disabled={!af.code||!af.name}>Create</Button>
          </div>
        </form>
      </div>
    </div>}
  </div>);
}
