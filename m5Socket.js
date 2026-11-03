import express from 'express';
import cors from 'cors';

const app = express();
const PORT = Number(process.env.M5_API_PORT || 8090);
const ALLOWED_ORIGIN = process.env.M5_ALLOWED_ORIGIN || '*';

app.use(express.json({ limit: '1mb' }));
app.use(
  cors({
    origin: ALLOWED_ORIGIN === '*' ? true : ALLOWED_ORIGIN,
  })
);

let latestSqueeze = null;

app.get('/api/health', (_req, res) => {
  res.json({
    success: true,
    message: 'M5 squeeze API is running',
    time: new Date().toISOString(),
  });
});

app.post('/api/squeeze-event', (req, res) => {
  const { user_id, device_id, strike_value, raw_data } = req.body || {};

  if (!user_id || !device_id || typeof strike_value !== 'number') {
    return res.status(400).json({
      success: false,
      message: 'Invalid payload. Required: user_id, device_id, strike_value(number).',
    });
  }

  latestSqueeze = {
    user_id: Number(user_id),
    device_id: String(device_id),
    strike_value,
    raw_data: raw_data ?? {},
    received_at: new Date().toISOString(),
  };

  return res.json({
    success: true,
    message: 'Squeeze event received',
    data: latestSqueeze,
  });
});

app.get('/api/latest-squeeze', (_req, res) => {
  res.json({
    success: true,
    data: latestSqueeze,
  });
});

app.listen(PORT, () => {
  console.log(`M5 squeeze API running on http://localhost:${PORT}`);
});
