import React, { useState, useEffect } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import {
  Document, Packer, Paragraph, TextRun, Table, TableRow, TableCell,
  AlignmentType, WidthType, BorderStyle, ImageRun, PageBreak, PageOrientation,
  TableLayoutType, VerticalAlign
} from 'docx';
import { saveAs } from 'file-saver';

const API_URL = 'https://buildphotoapp.ru/backend/api.php';

const STATUS_LABELS = {
  pending:   { label: 'В работе',    color: '#d97706' },
  review:    { label: 'На проверке', color: '#2563eb' },
  completed: { label: 'Выполнено',   color: '#16a34a' },
  rejected:  { label: 'Отклонено',   color: '#dc2626' },
};

function fmtDate(d) {
  if (!d) return '';
  return new Date(d).toLocaleDateString('ru-RU',{day:'2-digit',month:'2-digit',year:'numeric'}) + 'г.';
}

// Константы документа — точно по шаблону Word
const PW     = 16840;
const PH     = 11900;
const MARGIN_TOP    = 568;
const MARGIN_RIGHT  = 240;
const MARGIN_BOTTOM = 40;
const MARGIN_LEFT   = 400;
const CW     = 16240; // ширина таблицы из шаблона
const OUTER  = { style: BorderStyle.SINGLE, size: 4, color: '000000' };
const INNER  = { style: BorderStyle.DOTTED, size: 2, color: '000000' };
const PHOTOS_PER_PAGE = 3;
const ROWS_PER_PAGE   = 1;

// Ширина колонки в px (5413 twips / 1440 * 96 = ~361px), минус отступы
const PHOTO_W = 340; // px, вписывается в колонку
const PHOTO_H = 253; // px, соотношение 4:3
const COL_W   = Math.round(CW / 3);         // ~5413 twips на колонку

const PROXY_URL = 'https://buildphotoapp.ru/backend/proxy.php?file=';

function toProxyUrl(url) {
  const filename = url.split('/').pop().split('?')[0];
  return PROXY_URL + encodeURIComponent(filename);
}

async function fetchImg(url) {
  const proxyUrl = toProxyUrl(url);
  const cleanUrl = url.toLowerCase().split('?')[0];
  const type = cleanUrl.endsWith('.png') ? 'png' : 'jpg';

  try {
    const r = await fetch(proxyUrl);
    if (r.ok) {
      const buf = await r.arrayBuffer();
      if (buf.byteLength > 0) return { data: new Uint8Array(buf), type };
    }
  } catch (_) {}

  try {
    const r = await fetch(url);
    if (r.ok) {
      const buf = await r.arrayBuffer();
      if (buf.byteLength > 0) return { data: new Uint8Array(buf), type };
    }
  } catch (_) {}

  return null;
}

function makePage(task, photos3, bufs3, headerInfo) {
  const rows = [];

  // Шапка
  const hlines = [
    headerInfo.org      && { text: headerInfo.org,      bold: true,  color: '000000' },
    headerInfo.contract && { text: headerInfo.contract, bold: true,  color: '000000' },
    headerInfo.type     && { text: headerInfo.type,     bold: true,  color: '000000' },
    headerInfo.period   && { text: headerInfo.period,   bold: true,  color: 'C00000' },
  ].filter(Boolean);

  if (hlines.length) {
    rows.push(new TableRow({ children: [new TableCell({
      columnSpan: 3,
      borders: { top: OUTER, bottom: INNER, left: OUTER, right: OUTER },
      margins: { top: 80, bottom: 80, left: 200, right: 200 },
      children: hlines.map(l => new Paragraph({
        alignment: AlignmentType.CENTER,
        spacing: { after: 0, before: 0, line: 276 },
        children: [new TextRun({ text: l.text, font: 'Times New Roman', size: 18, bold: l.bold, color: l.color })]
      }))
    })] }));
  }

  // Строка описания
  const line = [task.executor_name || task.executor, fmtDate(task.created_at), task.title].filter(Boolean).join(' // ');
  rows.push(new TableRow({ children: [new TableCell({
    columnSpan: 3,
    borders: { top: INNER, bottom: INNER, left: OUTER, right: OUTER },
    margins: { top: 40, bottom: 40, left: 100, right: 100 },
    children: [new Paragraph({
      spacing: { after: 0, before: 0 },
      children: [new TextRun({ text: line, font: 'Times New Roman', size: 16, color: '000000' })]
    })]
  })] }));

  // Ряд с 3 фото
  const rowH = Math.round((PHOTO_H * 1440) / 96) + 40;
  rows.push(new TableRow({
    height: { value: rowH, rule: 'atLeast' },
    children: [0, 1, 2].map(col => {
      const imgObj = bufs3[col] || null;
      return new TableCell({
        width: { size: COL_W, type: WidthType.DXA },
        borders: {
          top:    INNER,
          bottom: INNER,
          left:   col === 0 ? OUTER : INNER,
          right:  col === 2 ? OUTER : INNER,
        },
        margins: { top: 20, bottom: 20, left: 20, right: 20 },
        children: (imgObj && imgObj.data)
          ? [new Paragraph({ spacing: { after: 0, before: 0 }, children: [new ImageRun({ data: imgObj.data, transformation: { width: PHOTO_W, height: PHOTO_H }, type: imgObj.type })] })]
          : [new Paragraph({ children: [] })],
      });
    })
  }));

  // Пустая строка снизу
  rows.push(new TableRow({
    height: { value: 400, rule: 'atLeast' },
    children: [new TableCell({
      columnSpan: 3,
      borders: { top: INNER, bottom: OUTER, left: OUTER, right: OUTER },
      children: [new Paragraph({ children: [] })]
    })]
  }));

  return new Table({
    width: { size: CW, type: WidthType.DXA },
    columnWidths: [COL_W, COL_W, CW - COL_W * 2],
    layout: TableLayoutType.FIXED,
    rows
  });
}

function makeTitleSection(task, headerInfo) {
  const OUTER_BORDER = { style: BorderStyle.SINGLE, size: 6, color: '000000' };
  const INNER_BORDER = { style: BorderStyle.SINGLE, size: 2, color: '000000' };

  const TW = 9000;

  const cell = (children, opts = {}) => new TableCell({
    verticalAlign: VerticalAlign.CENTER,
    borders: {
      top:    opts.topBorder    || INNER_BORDER,
      bottom: opts.bottomBorder || INNER_BORDER,
      left:   OUTER_BORDER,
      right:  OUTER_BORDER,
    },
    margins: { top: opts.mt || 160, bottom: opts.mb || 160, left: 400, right: 400 },
    children,
  });

  const par = (text, opts = {}) => new Paragraph({
    alignment: opts.align || AlignmentType.CENTER,
    spacing: { before: opts.before || 0, after: opts.after || 0, line: 276 },
    children: [new TextRun({
      text: text || '',
      font: 'Times New Roman',
      size: opts.size || 24,
      bold: opts.bold || false,
      color: opts.color || '000000',
      underline: opts.underline ? {} : undefined,
    })],
  });

  const emptyPar = () => new Paragraph({ children: [] });

  const rows = [];

  rows.push(new TableRow({
    height: { value: 900, rule: 'atLeast' },
    children: [cell([
      par(headerInfo.org || '', { bold: true, size: 22 }),
      headerInfo.contract ? par(headerInfo.contract, { bold: true, size: 22, before: 60 }) : emptyPar(),
    ], { topBorder: OUTER_BORDER })]
  }));

  rows.push(new TableRow({
    height: { value: 800, rule: 'exact' },
    children: [cell([emptyPar()])]
  }));

  rows.push(new TableRow({
    height: { value: 900, rule: 'atLeast' },
    children: [cell([
      par(headerInfo.type || '', { bold: true, size: 22 }),
      headerInfo.period ? par(headerInfo.period, { bold: true, size: 22, underline: true, before: 80 }) : emptyPar(),
      headerInfo.contract ? par(`(${headerInfo.contract})`, { size: 22, before: 80 }) : emptyPar(),
    ])]
  }));

  rows.push(new TableRow({
    height: { value: 800, rule: 'exact' },
    children: [cell([emptyPar()])]
  }));

  rows.push(new TableRow({
    height: { value: 900, rule: 'atLeast' },
    children: [cell([
      headerInfo.address
        ? par(`Адрес: ${headerInfo.address}`, { align: AlignmentType.LEFT, bold: true, size: 22, underline: true })
        : emptyPar(),
      headerInfo.quantity
        ? par(`Количество конструкций: ${headerInfo.quantity}`, { align: AlignmentType.LEFT, bold: true, size: 22, underline: true, before: 80 })
        : emptyPar(),
      headerInfo.reportPeriod
        ? par(`Отчетный период: ${headerInfo.reportPeriod}`, { align: AlignmentType.LEFT, bold: true, size: 22, color: 'C00000', underline: true, before: 80 })
        : emptyPar(),
    ])]
  }));

  rows.push(new TableRow({
    height: { value: 800, rule: 'exact' },
    children: [cell([emptyPar()])]
  }));

  rows.push(new TableRow({
    height: { value: 700, rule: 'atLeast' },
    children: [cell([
      par(headerInfo.city ? `г. ${headerInfo.city}` : 'г. Москва', { size: 22 }),
      par(new Date().getFullYear() + ' г.', { size: 22, before: 60 }),
    ], { bottomBorder: OUTER_BORDER })]
  }));

  const titleTable = new Table({
    width: { size: TW, type: WidthType.DXA },
    columnWidths: [TW],
    layout: TableLayoutType.FIXED,
    alignment: AlignmentType.CENTER,
    rows,
  });

  // Страница A4 портрет: высота 16840, поля 1440 → рабочая зона 13960
  // Центрируем таблицу вертикально: topSpacing = (13960 - ~6800) / 2
  const topSpacing = 3580;

  return {
    properties: {
      page: {
        size: { width: 11900, height: 16840 },
        margin: { top: 1440, right: 1440, bottom: 1440, left: 1440 },
      },
    },
    children: [
      new Paragraph({ spacing: { before: 0, after: topSpacing }, children: [] }),
      titleTable,
    ],
  };
}

async function exportTaskDoc(task, photos, headerInfo) {
  const buffers = await Promise.all(photos.map(p => fetchImg(p.url)));
  const total   = Math.max(1, Math.ceil(photos.length / PHOTOS_PER_PAGE));
  const children = [];
  for (let i = 0; i < total; i++) {
    if (i > 0) children.push(new Paragraph({ children: [new PageBreak()] }));
    children.push(makePage(
      task,
      photos.slice(i * PHOTOS_PER_PAGE, i * PHOTOS_PER_PAGE + PHOTOS_PER_PAGE),
      buffers.slice(i * PHOTOS_PER_PAGE, i * PHOTOS_PER_PAGE + PHOTOS_PER_PAGE),
      headerInfo
    ));
  }
  const doc = new Document({
    styles: { default: { document: { run: { font: 'Times New Roman', size: 20 } } } },
    sections: [
      // Титульный лист
      makeTitleSection(task, headerInfo),
      // Страницы с фотографиями
      {
        properties: {
          page: {
            size: { width: 11900, height: 16840, orientation: PageOrientation.LANDSCAPE },
            margin: { top: MARGIN_TOP, right: MARGIN_RIGHT, bottom: MARGIN_BOTTOM, left: MARGIN_LEFT },
          },
        },
        children,
      }
    ]
  });
  const blob = await Packer.toBlob(doc);
  const name = (task.title || 'задача').replace(/[^а-яёa-z0-9\s]/gi, '').trim().slice(0, 40);
  saveAs(blob, `задача-${task.id}-${name}.docx`);
}

// ── Модалка шапки ─────────────────────────────────────────────
function ExportModal({ onClose, onExport, loading }) {
  const [org, setOrg]           = useState('');
  const [contract, setContract] = useState('');
  const [type, setType]         = useState('');
  const [period, setPeriod]     = useState('');
  const [city, setCity]         = useState('');
  const [address, setAddress]   = useState('');
  const [quantity, setQuantity] = useState('');
  const [reportPeriod, setReportPeriod] = useState('');
  return (
    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
      <div className="bg-white rounded-xl p-6 max-w-lg w-full mx-4 shadow-xl">
        <h3 className="text-xl font-bold text-gray-800 mb-1">Экспорт задачи в Word</h3>
        <p className="text-sm text-gray-500 mb-4">Заполните шапку отчёта (необязательно)</p>
        <div className="space-y-3">
          {[
            { label:'Организация',             val:org,      set:setOrg,      ph:'ООО "Зодиак-Электро"' },
            { label:'Договор',                 val:contract, set:setContract, ph:'Договор РСП' },
            { label:'Тип / объект',            val:type,     set:setType,     ph:'АХП тип НА-10 – 6 шт.' },
            { label:'Описание работ', val:period,   set:setPeriod,   ph:'Демонтаж конструкции.' },
            { label:'Город',                   val:city,     set:setCity,     ph:'Москва' },
            { label:'Адрес объекта',           val:address,  set:setAddress,  ph:'ул. Ленина, д. 1' },
            { label:'Количество конструкций',   val:quantity, set:setQuantity, ph:'6 шт.' },
            { label:'Отчетный период',           val:reportPeriod, set:setReportPeriod, ph:'с 01.01.2026г. по 31.01.2026г.' },
          ].map(({ label, val, set, ph }) => (
            <div key={label}>
              <label className="block text-xs font-medium text-gray-500 mb-1">{label}</label>
              <input type="text" value={val} onChange={e => set(e.target.value)} placeholder={ph}
                className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-purple-500 outline-none" />
            </div>
          ))}
        </div>
        <div className="flex gap-3 mt-5">
          <button onClick={() => onExport({ org, contract, type, period, city, address, quantity, reportPeriod })} disabled={loading}
            className={`flex-1 py-3 rounded-lg font-medium text-sm transition ${loading ? 'bg-gray-300 text-gray-500 cursor-not-allowed' : 'bg-green-600 text-white hover:bg-green-700'}`}>
            {loading ? 'Создание...' : 'Скачать'}
          </button>
          <button onClick={onClose} disabled={loading} className="px-5 py-3 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition text-sm">Отмена</button>
        </div>
      </div>
    </div>
  );
}

// ── TaskDetail ────────────────────────────────────────────────
function TaskDetail() {
  const navigate = useNavigate();
  const { id }   = useParams();
  const token = localStorage.getItem('token');
  const user  = JSON.parse(localStorage.getItem('user') || '{}');

  const [task,         setTask]         = useState(null);
  const [loading,      setLoading]      = useState(true);
  const [photos,       setPhotos]       = useState([]);
  const [viewIdx,      setViewIdx]      = useState(null);
  const [notification, setNotification] = useState(null);
  const [showReject,   setShowReject]   = useState(false);
  const [rejectReason, setRejectReason] = useState('');
  const [showExport,   setShowExport]   = useState(false);
  const [exporting,    setExporting]    = useState(false);

  const notify = (type, message) => {
    setNotification({ type, message });
    setTimeout(() => setNotification(null), 3000);
  };

  useEffect(() => {
    (async () => {
      try {
        const r = await fetch(`${API_URL}/tasks/${id}?token=${token}`);
        if (r.status === 401) { localStorage.clear(); navigate('/login'); return; }
        if (!r.ok) throw new Error();
        setTask(await r.json());
      } catch { console.error('Ошибка загрузки задачи'); }
      finally { setLoading(false); }
    })();
  }, [id]);

  useEffect(() => {
    if (!id) return;
    (async () => {
      try {
        const r = await fetch(`${API_URL}/photos/${id}?token=${token}`);
        const d = await r.json();
        setPhotos(Array.isArray(d) ? d : []);
      } catch { console.error('Ошибка загрузки фото'); }
    })();
  }, [id]);

  const updateStatus = async (status, reason) => {
    try {
      const body = { status };
      if (reason) body.rejection_reason = reason;
      const r = await fetch(`${API_URL}/tasks/${id}?token=${token}`, {
        method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body)
      });
      if (!r.ok) throw new Error();
      setTask(p => ({ ...p, status, rejection_reason: reason || null }));
      const msgs = { review: 'Задача отправлена на проверку!', completed: 'Задача принята!', rejected: 'Задача отклонена', pending: 'Задача возвращена в работу' };
      notify(status === 'rejected' ? 'error' : 'success', msgs[status]);
      if (status === 'completed') setTimeout(() => navigate('/tasks'), 1500);
    } catch { notify('error', 'Ошибка обновления задачи'); }
  };

  const handleReject = async () => {
    if (!rejectReason.trim()) { notify('error', 'Укажите причину'); return; }
    setShowReject(false);
    await updateStatus('rejected', rejectReason.trim());
  };

  const handleFile = async (e) => {
    const file = e.target.files[0];
    if (!file) return;
    if (!['image/jpeg', 'image/png', 'image/jpg'].includes(file.type)) { notify('error', 'Только PNG или JPG'); return; }
    if (file.size > 5 * 1024 * 1024) { notify('error', 'Максимум 5 МБ'); return; }
    if (photos.length >= task.required_photos) { notify('error', `Лимит: ${task.required_photos} фото`); return; }
    const fd = new FormData();
    fd.append('photo', file);
    fd.append('task_id', id);
    fd.append('token', token);
    try {
      const r = await fetch(`${API_URL}/photos`, { method: 'POST', body: fd });
      if (!r.ok) throw new Error();
      const result = await r.json();
      setPhotos(p => [...p, result]);
      notify('success', 'Фото загружено!');
    } catch { notify('error', 'Ошибка загрузки фото'); }
    finally { e.target.value = ''; }
  };

  const removePhoto = async (pid) => {
    if (!window.confirm('Удалить фото?')) return;
    try {
      const r = await fetch(`${API_URL}/photos/${pid}?token=${token}`, { method: 'DELETE' });
      if (!r.ok) throw new Error();
      setPhotos(p => p.filter(x => x.id !== pid));
      notify('success', 'Фото удалено');
    } catch { notify('error', 'Ошибка удаления'); }
  };

  const handleExport = async (headerInfo) => {
    setExporting(true);
    try { await exportTaskDoc(task, photos, headerInfo); }
    catch (e) { notify('error', 'Ошибка экспорта'); console.error(e); }
    finally { setExporting(false); setShowExport(false); }
  };

  const prevPhoto = () => setViewIdx(i => (i > 0 ? i - 1 : photos.length - 1));
  const nextPhoto = () => setViewIdx(i => (i < photos.length - 1 ? i + 1 : 0));

  // Клавиши стрелок в просмотрщике
  useEffect(() => {
    if (viewIdx === null) return;
    const handler = (e) => {
      if (e.key === 'ArrowLeft')  prevPhoto();
      if (e.key === 'ArrowRight') nextPhoto();
      if (e.key === 'Escape')     setViewIdx(null);
    };
    window.addEventListener('keydown', handler);
    return () => window.removeEventListener('keydown', handler);
  }, [viewIdx, photos.length]);

  if (loading) return <div className="flex items-center justify-center min-h-screen bg-gray-50"><p>Загрузка...</p></div>;
  if (!task)   return <div className="flex items-center justify-center min-h-screen bg-gray-50"><p>Задача не найдена</p></div>;

  const reqPhotos = task.required_photos || 0;
  const si        = STATUS_LABELS[task.status] || STATUS_LABELS.pending;
  const isWorker  = user.role === 'worker';
  const isAdmin   = user.role === 'admin';
  const canSend   = isWorker && task.status === 'pending' && photos.length >= reqPhotos;
  const canReview = isAdmin  && task.status === 'review';
  const canUpload = isWorker && (task.status === 'pending' || task.status === 'rejected');

  return (
    <div className="flex flex-col min-h-screen bg-gray-50 p-6">

      {/* Уведомление */}
      {notification && (
        <div className={`fixed top-6 right-6 z-50 px-6 py-4 rounded-xl shadow-lg text-white font-medium ${notification.type === 'success' ? 'bg-green-500' : 'bg-red-500'}`}>
          {notification.message}
        </div>
      )}

      {/* Модалка отклонения */}
      {showReject && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
          <div className="bg-white rounded-xl p-6 max-w-md w-full mx-4 shadow-xl">
            <h3 className="text-xl font-bold text-gray-800 mb-4">Причина отклонения</h3>
            <textarea value={rejectReason} onChange={e => setRejectReason(e.target.value)} rows="4"
              placeholder="Укажите причину..."
              className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-500 outline-none mb-4" />
            <div className="flex gap-3">
              <button onClick={handleReject} className="flex-1 py-3 bg-red-500 text-white rounded-lg hover:bg-red-600 transition font-medium">Отклонить</button>
              <button onClick={() => { setShowReject(false); setRejectReason(''); }} className="flex-1 py-3 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400 transition">Отмена</button>
            </div>
          </div>
        </div>
      )}

      {/* Модалка экспорта */}
      {showExport && <ExportModal onClose={() => setShowExport(false)} onExport={handleExport} loading={exporting} />}

      {/* Просмотрщик фото */}
      {viewIdx !== null && photos[viewIdx] && (
        <div
          className="fixed inset-0 z-50 flex flex-col items-center justify-center bg-black bg-opacity-90"
          onClick={() => setViewIdx(null)}
        >
          {/* Основной ряд: стрелка — фото — стрелка */}
          <div className="flex items-center gap-4" onClick={e => e.stopPropagation()}>

            {/* Стрелка влево */}
            <button
              onClick={e => { e.stopPropagation(); prevPhoto(); }}
              className="w-12 h-12 flex-shrink-0 flex items-center justify-center rounded-full transition"
              style={{ background: 'rgba(255,255,255,0.18)' }}
              onMouseEnter={e => e.currentTarget.style.background='rgba(255,255,255,0.35)'}
              onMouseLeave={e => e.currentTarget.style.background='rgba(255,255,255,0.18)'}
            >
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="white" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" width="28" height="28">
                <polyline points="15 18 9 12 15 6"/>
              </svg>
            </button>

            {/* Фото + крестик */}
            <div className="relative">
              <button
                onClick={() => setViewIdx(null)}
                className="absolute -top-4 -right-4 w-9 h-9 flex items-center justify-center rounded-full bg-white text-gray-800 shadow-lg hover:bg-gray-100 transition font-bold text-lg z-10"
              >
                ✕
              </button>
              <img
                src={photos[viewIdx].url}
                alt="Просмотр"
                className="max-h-[80vh] max-w-[75vw] object-contain rounded-lg shadow-2xl"
              />
            </div>

            {/* Стрелка вправо */}
            <button
              onClick={e => { e.stopPropagation(); nextPhoto(); }}
              className="w-12 h-12 flex-shrink-0 flex items-center justify-center rounded-full transition"
              style={{ background: 'rgba(255,255,255,0.18)' }}
              onMouseEnter={e => e.currentTarget.style.background='rgba(255,255,255,0.35)'}
              onMouseLeave={e => e.currentTarget.style.background='rgba(255,255,255,0.18)'}
            >
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="white" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" width="28" height="28">
                <polyline points="9 18 15 12 9 6"/>
              </svg>
            </button>

          </div>

          {/* Счётчик под фото */}
          <p className="text-white text-sm mt-4 opacity-60 select-none" onClick={e => e.stopPropagation()}>
            {viewIdx + 1} / {photos.length}
          </p>
        </div>
      )}

      {/* Карточка задачи */}
      <div className="max-w-5xl mx-auto w-full bg-white p-6 rounded-xl shadow-lg">

        {/* Заголовок */}
        <div className="flex justify-between items-start mb-3">
          <h1 className="text-3xl font-bold text-gray-800 leading-tight flex-1 mr-4"
            style={{ wordBreak: 'break-all', overflowWrap: 'anywhere' }}>
            {task.title}
          </h1>
          {isAdmin && (
            <button onClick={() => setShowExport(true)}
              className="px-4 py-2 text-sm rounded-lg border font-medium whitespace-nowrap transition bg-green-50 text-green-700 border-green-300 hover:bg-green-100 flex-shrink-0">
              Экспорт в Word
            </button>
          )}
        </div>

        <p className="text-base text-gray-600 mb-1">Описание: {task.description || 'Нет описания'}</p>
        <p className="text-sm text-gray-500 mb-1">Исполнитель: {task.executor_name ? `${task.executor_name} (${task.executor})` : task.executor}</p>
        <p className="text-sm text-gray-500 mb-4">Статус: <span style={{ color: si.color }} className="font-semibold">{si.label}</span></p>

        {task.status === 'rejected' && (
          <div className="mb-4 px-4 py-4 bg-red-50 border border-red-200 rounded-lg">
            <p className="text-red-700 font-semibold mb-1">Задача отклонена</p>
            {task.rejection_reason && <p className="text-red-600 mb-2">Причина: {task.rejection_reason}</p>}
            {isWorker && <p className="text-sm text-gray-600">Загрузите новые фото и нажмите <span className="font-medium text-blue-700">«Отправить на проверку»</span>.</p>}
          </div>
        )}
        {task.status === 'review' && (
          <div className="mb-4 px-4 py-3 bg-blue-50 border border-blue-200 rounded-lg text-blue-700">
            Задача отправлена на проверку администратору.
          </div>
        )}
        {task.status === 'completed' && (
          <div className="mb-4 px-4 py-3 bg-green-50 border border-green-200 rounded-lg text-green-700 font-medium">
            Задача успешно выполнена и принята.
          </div>
        )}

        {/* Фотографии — горизонтальная прокрутка */}
        <div className="mb-6">
          <h2 className="text-xl font-bold text-gray-800 mb-4">Фотографии ({photos.length}/{reqPhotos})</h2>
          <div className="flex flex-row gap-3 overflow-x-auto pb-2" style={{ scrollSnapType: 'x mandatory' }}>
            {photos.map((photo, index) => (
              <div key={photo.id} className="relative group flex-shrink-0" style={{ width: 240, height: 180, scrollSnapAlign: 'start' }}>
                <img
                  src={photo.url}
                  alt="фото"
                  className="w-full h-full object-cover rounded-lg cursor-pointer hover:opacity-90 transition"
                  onClick={() => setViewIdx(index)}
                />
                {canUpload && (
                  <button
                    onClick={() => removePhoto(photo.id)}
                    className="absolute top-2 right-2 w-7 h-7 flex items-center justify-center bg-red-500 text-white rounded-full opacity-0 group-hover:opacity-100 transition text-sm font-bold shadow"
                  >
                    ✕
                  </button>
                )}
              </div>
            ))}
            {canUpload && photos.length < reqPhotos && (
              <label className="flex flex-col items-center justify-center border-2 border-dashed border-gray-300 rounded-lg hover:border-purple-500 hover:bg-purple-50 transition cursor-pointer flex-shrink-0" style={{ width: 240, height: 180, scrollSnapAlign: 'start' }}>
                <span className="text-3xl text-gray-400 mb-1">+</span>
                <span className="text-sm text-gray-500">Добавить фото</span>
                <input type="file" accept="image/png,image/jpeg,image/jpg" onChange={handleFile} className="hidden" />
              </label>
            )}
          </div>
        </div>

        {/* Кнопки исполнителя */}
        {isWorker && (
          <div className="flex gap-4">
            {task.status === 'review' ? (
              <>
                <div className="flex-1 py-3 bg-blue-100 text-blue-700 rounded-lg text-center font-medium">Ожидает проверки</div>
                <button onClick={() => updateStatus('pending')} className="px-6 py-3 bg-gray-500 text-white rounded-lg hover:bg-gray-600 transition font-medium">Отозвать</button>
              </>
            ) : task.status === 'completed' ? (
              <div className="flex-1 py-3 bg-green-100 text-green-700 rounded-lg text-center font-medium">Принято</div>
            ) : (
              <button onClick={() => updateStatus('review')} disabled={!canSend}
                className={`flex-1 py-3 rounded-lg transition font-medium ${canSend ? 'bg-blue-600 text-white hover:bg-blue-700' : 'bg-gray-300 text-gray-500 cursor-not-allowed'}`}>
                {photos.length < reqPhotos ? `Загрузите ещё ${reqPhotos - photos.length} фото` : 'Отправить на проверку'}
              </button>
            )}
            <button onClick={() => navigate('/tasks')} className="px-6 py-3 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition">Назад</button>
          </div>
        )}

        {/* Кнопки администратора */}
        {isAdmin && (
          <div className="flex gap-4">
            {canReview && (
              <>
                <button onClick={() => updateStatus('completed')} className="flex-1 py-3 bg-green-600 text-white rounded-lg hover:bg-green-700 transition font-medium">Принять задачу</button>
                <button onClick={() => setShowReject(true)} className="flex-1 py-3 bg-red-500 text-white rounded-lg hover:bg-red-600 transition font-medium">Отклонить</button>
              </>
            )}
            <button onClick={() => navigate(`/tasks/${id}/edit`)} className="flex-1 py-3 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition font-medium">Редактировать задачу</button>
            {!canReview && <button onClick={() => navigate('/tasks')} className="flex-1 py-3 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition font-medium">Назад</button>}
          </div>
        )}
      </div>
    </div>
  );
}

export default TaskDetail;