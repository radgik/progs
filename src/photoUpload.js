const PROD_API = 'https://buildphotoapp.ru/backend/api.php';

/** В dev обходим Vite-прокси для multipart — он часто ломает загрузку файлов */
export function resolveUploadApiUrl(apiUrl) {
  if (!apiUrl || apiUrl.startsWith('/') || /localhost|127\.0\.0\.1/i.test(apiUrl)) {
    return PROD_API;
  }
  return apiUrl;
}

function buildUploadUrl(apiUrl, token, { taskId, executionId }) {
  const base = resolveUploadApiUrl(apiUrl);
  const params = new URLSearchParams();
  params.set('upload', '1');
  if (token) params.set('token', token);
  if (executionId) params.set('execution_id', String(executionId));
  else if (taskId) params.set('task_id', String(taskId));
  return `${base}?${params}`;
}

function buildPhotosUrl(apiUrl, token, { taskId, executionId }) {
  const base = resolveUploadApiUrl(apiUrl).replace(/\/$/, '');
  const params = new URLSearchParams();
  if (token) params.set('token', token);
  if (executionId) params.set('execution_id', String(executionId));
  else if (taskId) params.set('task_id', String(taskId));
  const qs = params.toString();
  return `${base}/photos${qs ? `?${qs}` : ''}`;
}

function makeFormData(file, token, { taskId, executionId }) {
  const fd = new FormData();
  fd.append('photo', file);
  if (token) fd.append('token', token);
  if (executionId) fd.append('execution_id', String(executionId));
  else if (taskId) fd.append('task_id', String(taskId));
  return fd;
}

function authHeaders(token) {
  return token ? { Authorization: `Bearer ${token}` } : {};
}

function fileToBase64(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(reader.result);
    reader.onerror = () => reject(new Error('Не удалось прочитать файл'));
    reader.readAsDataURL(file);
  });
}

async function parseResponse(r) {
  const text = await r.text();
  let data = {};
  try {
    data = text ? JSON.parse(text) : {};
  } catch {
    throw new Error(text?.slice(0, 200) || `Ошибка сервера (${r.status})`);
  }
  if (!r.ok) throw new Error(data.error || `Ошибка загрузки (${r.status})`);
  if (data.error) throw new Error(data.error);
  return data;
}

export async function uploadPhotoFile({ apiUrl, token, taskId, executionId, file }) {
  if (!token) throw new Error('Сессия истекла. Войдите снова.');
  if (!taskId && !executionId) throw new Error('Не указана задача');
  if (!file) throw new Error('Файл не выбран');

  const ids = { taskId, executionId };
  const resolved = resolveUploadApiUrl(apiUrl);
  const base = resolved.replace(/\/api\.php.*$/i, '');
  const query = buildUploadUrl(apiUrl, token, ids).split('?')[1];
  const fallbackUrl = `${base}/upload_photo.php?${query}`;

  const errors = [];

  const attempts = [
    {
      label: 'upload',
      run: () => fetch(buildUploadUrl(apiUrl, token, ids), {
        method: 'POST',
        headers: authHeaders(token),
        body: makeFormData(file, token, ids),
      }),
    },
    {
      label: 'photos',
      run: () => fetch(buildPhotosUrl(apiUrl, token, ids), {
        method: 'POST',
        headers: authHeaders(token),
        body: makeFormData(file, token, ids),
      }),
    },
    {
      label: 'base64',
      run: async () => {
        const photo_base64 = await fileToBase64(file);
        const body = { photo_base64, filename: file.name, token };
        if (executionId) body.execution_id = Number(executionId);
        else body.task_id = Number(taskId);

        return fetch(buildUploadUrl(apiUrl, token, ids), {
          method: 'POST',
          headers: { ...authHeaders(token), 'Content-Type': 'application/json' },
          body: JSON.stringify(body),
        });
      },
    },
    {
      label: 'upload_photo.php',
      run: () => fetch(fallbackUrl, {
        method: 'POST',
        headers: authHeaders(token),
        body: makeFormData(file, token, ids),
      }),
    },
  ];

  for (const { label, run } of attempts) {
    try {
      const r = await run();
      const data = await parseResponse(r);
      if (data.id || data.url || data.success) return data;
      throw new Error('Сервер не вернул данные фото');
    } catch (e) {
      errors.push(`${label}: ${e.message}`);
    }
  }

  throw new Error(errors.filter(Boolean).join(' · ') || 'Не удалось загрузить фото');
}
