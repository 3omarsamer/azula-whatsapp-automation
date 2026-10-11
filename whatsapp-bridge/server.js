const express = require('express');
const wppconnect = require('@wppconnect-team/wppconnect');

const app = express();
app.use(express.json());

const PORT = process.env.PORT || 3001;
const SESSION = 'azula-code-test2';

let client = null;
let connectionStatus = 'STARTING';
let lastStatus = null;
let lastError = null;
let lastIncoming = null;
let lastLinkCode = null;
let lastLinkCodeAt = null;

// Self-healing watchdog
let watchdogFailures = 0;
let watchdogRecovering = false;
let watchdogStarted = false;
let lastHealthyAt = null;

const WATCHDOG_INTERVAL_MS = 30000;
const WATCHDOG_TIMEOUT_MS = 10000;
const WATCHDOG_FAILURE_LIMIT = 3;

const processedMessageIds = new Set();
let incomingQueue = [];
const latestPollSelections = new Map();
let pollerStarted = false;
const pollerStartupTimestamp = Math.floor(Date.now() / 1000);


const fs = require('fs');
const path = require('path');

const DATA_DIR = '/app/data';
const STATE_FILE = path.join(DATA_DIR, 'state.json');

function savePersistentState() {
  try {
    if (!fs.existsSync(DATA_DIR)) {
      fs.mkdirSync(DATA_DIR, { recursive: true });
    }

    const state = {
      processedMessageIds: Array.from(processedMessageIds),
      incomingQueue
    };

    fs.writeFileSync(STATE_FILE, JSON.stringify(state, null, 2));
  } catch (error) {
    console.error('[AZULA] State save error:', error?.message || String(error));
  }
}

function loadPersistentState() {
  try {
    if (!fs.existsSync(STATE_FILE)) {
      console.log('[AZULA] No persistent state yet');
      return;
    }

    const raw = fs.readFileSync(STATE_FILE, 'utf8');
    const state = JSON.parse(raw);

    if (Array.isArray(state.processedMessageIds)) {
      for (const id of state.processedMessageIds) {
        processedMessageIds.add(id);
      }
    }

    if (Array.isArray(state.incomingQueue)) {
      incomingQueue = state.incomingQueue.slice(-500);
    }

    console.log(
      '[AZULA] Persistent state loaded:',
      processedMessageIds.size,
      'processed IDs,',
      incomingQueue.length,
      'queued messages'
    );

  } catch (error) {
    console.error('[AZULA] State load error:', error?.message || String(error));
  }
}

function cleanStaleChromeLocks() {
  const profileDir = path.join('/app/tokens', SESSION);

  for (const name of ['SingletonLock', 'SingletonCookie', 'SingletonSocket']) {
    const file = path.join(profileDir, name);

    try {
      fs.unlinkSync(file);
      console.log('[AZULA] Removed stale Chromium lock:', name);
    } catch (error) {
      if (error.code !== 'ENOENT') {
        console.error('[AZULA] Could not remove lock:', name, error.message);
      }
    }
  }
}




async function pollIncomingMessages() {
  if (!client || connectionStatus !== 'CONNECTED') return;

  try {
    const chats = await client.listChats({
      onlyGroups: false,
      onlyUsers: true,
      count: 20
    });

    for (const chat of chats.slice(0, 20)) {
      const chatId = chat?.id?._serialized || chat?.id;
      if (!chatId) continue;

      let messages = [];

      try {
        messages = await client.getMessages(chatId, { count: 10 });
      } catch (error) {
        continue;
      }

      for (const message of messages) {
        if (message?.fromMe) continue;

        const id =
          message?.id?._serialized ||
          (typeof message?.id === 'string' ? message.id : null);

        if (!id || processedMessageIds.has(id)) continue;

        processedMessageIds.add(id);

        const timestamp = Number(message?.timestamp || 0);

        // Old history: remember it but never queue it.
        if (!timestamp || timestamp < pollerStartupTimestamp) {
          continue;
        }

        const item = {
          id,
          from: message?.from || null,
          to: message?.to || null,
          body: message?.body || '',
          timestamp,
          detectedAt: new Date().toISOString()
        };

        incomingQueue.push(item);

        if (incomingQueue.length > 500) {
          incomingQueue = incomingQueue.slice(-500);
        }

        lastIncoming = item;

        savePersistentState();

        console.log(
          '[AZULA] New incoming message detected:',
          item.from,
          '-',
          item.body
        );
      }
    }

    if (processedMessageIds.size > 5000) {
      const keep = Array.from(processedMessageIds).slice(-3000);

      processedMessageIds.clear();

      for (const id of keep) {
        processedMessageIds.add(id);
      }
    }

  } catch (error) {
    console.error('[AZULA] Polling error:', error?.message || String(error));
  }
}

function startIncomingPoller() {
  if (pollerStarted) return;

  pollerStarted = true;

  console.log('[AZULA] Incoming polling started (5s)');

  const runPoll = async () => {
    await pollIncomingMessages();
    setTimeout(runPoll, 5000);
  };

  runPoll();
}


function isBrowserHealthError(error) {
  const message = String(error?.message || error || '').toLowerCase();

  return (
    message.includes('timed out') ||
    message.includes('runtime.callfunctionon') ||
    message.includes('target closed') ||
    message.includes('browserclose') ||
    message.includes('browser closed') ||
    message.includes('protocol error') ||
    message.includes('session closed')
  );
}


function registerSendHealthFailure(error) {
  if (!isBrowserHealthError(error)) {
    return;
  }

  watchdogFailures += 1;
  lastError = error?.message || String(error);

  console.error(
    `[AZULA] Send operation indicates unhealthy browser (${watchdogFailures}/${WATCHDOG_FAILURE_LIMIT}):`,
    lastError
  );

  if (watchdogFailures >= WATCHDOG_FAILURE_LIMIT && !watchdogRecovering) {
    recoverWhatsApp(lastError).catch((recoveryError) => {
      console.error(
        '[AZULA] Send-triggered recovery error:',
        recoveryError?.message || String(recoveryError)
      );
    });
  }
}


function withTimeout(promise, ms, label) {
  let timer;

  return Promise.race([
    promise,
    new Promise((_, reject) => {
      timer = setTimeout(() => {
        reject(new Error(`${label} timed out after ${ms}ms`));
      }, ms);
    })
  ]).finally(() => clearTimeout(timer));
}


async function recoverWhatsApp(reason) {
  if (watchdogRecovering) {
    return;
  }

  watchdogRecovering = true;
  connectionStatus = 'CONNECTING';
  lastError = reason || 'Watchdog recovery started';

  console.error('[AZULA] 🚨 Watchdog recovery started:', lastError);

  const oldClient = client;
  client = null;

  try {
    if (oldClient) {
      try {
        await withTimeout(
          oldClient.close(),
          10000,
          'Old WhatsApp client close'
        );

        console.log('[AZULA] Watchdog closed old WhatsApp client');
      } catch (error) {
        lastError = error?.message || String(error);

        console.error(
          '[AZULA] Watchdog could not cleanly close old client:',
          lastError
        );

        console.error(
          '[AZULA] Old Chromium may still be stuck. Exiting for clean Docker restart.'
        );

        setTimeout(() => process.exit(1), 1000);
        return;
      }
    }

    await new Promise((resolve) => setTimeout(resolve, 2000));

    connectionStatus = 'DISCONNECTED';

    await withTimeout(
      connectWhatsApp(),
      90000,
      'WhatsApp reconnect'
    );

    if (!client || connectionStatus !== 'CONNECTED') {
      throw new Error('WhatsApp reconnect did not reach CONNECTED state');
    }

    watchdogFailures = 0;
    lastHealthyAt = new Date().toISOString();
    lastError = null;

    console.log('[AZULA] ✅ Watchdog recovery successful');

  } catch (error) {
    lastError = error?.message || String(error);

    console.error(
      '[AZULA] ❌ Watchdog recovery failed:',
      lastError
    );

    console.error(
      '[AZULA] Exiting process so Docker can restart the container cleanly'
    );

    setTimeout(() => process.exit(1), 1000);

  } finally {
    watchdogRecovering = false;
  }
}


async function runWatchdogCheck() {
  if (watchdogRecovering) {
    return;
  }

  if (!client || connectionStatus !== 'CONNECTED') {
    watchdogFailures += 1;

    console.error(
      `[AZULA] Watchdog unhealthy: no connected client (${watchdogFailures}/${WATCHDOG_FAILURE_LIMIT})`
    );

  } else {
    try {
      const state = await withTimeout(
        client.getConnectionState(),
        WATCHDOG_TIMEOUT_MS,
        'WPPConnect health check'
      );

      lastStatus = state;

      const healthyStates = new Set([
        'CONNECTED',
        'INCHAT',
        'ISLOGGED',
        'inChat',
        'isLogged'
      ]);

      if (!healthyStates.has(String(state))) {
        throw new Error(`Unhealthy WhatsApp state: ${state}`);
      }

      watchdogFailures = 0;
      lastHealthyAt = new Date().toISOString();

      return;

    } catch (error) {
      watchdogFailures += 1;
      lastError = error?.message || String(error);

      console.error(
        `[AZULA] Watchdog health check failed (${watchdogFailures}/${WATCHDOG_FAILURE_LIMIT}):`,
        lastError
      );
    }
  }

  if (watchdogFailures >= WATCHDOG_FAILURE_LIMIT) {
    await recoverWhatsApp(
      lastError || 'WhatsApp failed consecutive watchdog checks'
    );
  }
}


function startWhatsAppWatchdog() {
  if (watchdogStarted) {
    return;
  }

  watchdogStarted = true;

  console.log(
    `[AZULA] WhatsApp watchdog started (${WATCHDOG_INTERVAL_MS / 1000}s interval)`
  );

  setInterval(() => {
    runWatchdogCheck().catch((error) => {
      console.error(
        '[AZULA] Watchdog unexpected error:',
        error?.message || String(error)
      );
    });
  }, WATCHDOG_INTERVAL_MS);
}


async function connectWhatsApp() {
  if (connectionStatus === 'CONNECTING' || connectionStatus === 'CONNECTED') {
    return;
  }

  connectionStatus = 'CONNECTING';
  lastError = null;

  console.log('[AZULA] Starting WhatsApp session:', SESSION);

  cleanStaleChromeLocks();

  try {
    client = await wppconnect.create({
      session: SESSION,

      // This is the method that already worked for our saved session.
      headless: true,
      logQR: false,
      autoClose: 0,

      puppeteerOptions: {
        protocolTimeout: 120000
      },

      browserArgs: [
        '--no-sandbox',
        '--disable-setuid-sandbox',
        '--disable-dev-shm-usage'
      ],

      statusFind: (status) => {
        lastStatus = status;
        console.log('[AZULA] WhatsApp status:', status);

        if (
          status === 'inChat' ||
          status === 'isLogged' ||
          status === 'qrReadSuccess'
        ) {
          connectionStatus = 'CONNECTED';
        }

        if (
          status === 'notLogged' ||
          status === 'browserClose' ||
          status === 'qrReadError' ||
          status === 'serverClose'
        ) {
          connectionStatus = 'DISCONNECTED';
        }
      },

      catchLinkCode: (code) => {
        lastLinkCode = code;
        lastLinkCodeAt = new Date().toISOString();
        console.log('[AZULA] New linking code generated');
      }
    });

    connectionStatus = 'CONNECTED';
    console.log('[AZULA] ✅ WhatsApp connected');

    startIncomingPoller();



    client.onAnyMessage((message) => {
      try {
        console.log(
          '[AZULA] ANY message:',
          message?.from || null,
          '->',
          message?.to || null,
          '| fromMe:',
          message?.fromMe,
          '|',
          message?.body || ''
        );

        if (!message?.fromMe) {
          lastIncoming = {
            from: message?.from || null,
            body: message?.body || '',
            id: message?.id?._serialized || message?.id || null,
            timestamp: new Date().toISOString()
          };

          console.log(
            '[AZULA] Incoming captured via onAnyMessage:',
            lastIncoming.from,
            '-',
            lastIncoming.body
          );
        }

      } catch (error) {
        console.error('[AZULA] onAnyMessage error:', error);
      }
    });


    client.onMessage((message) => {
      try {
        lastIncoming = {
          from: message?.from || null,
          body: message?.body || '',
          id: message?.id?._serialized || message?.id || null,
          timestamp: new Date().toISOString()
        };

        console.log(
          '[AZULA] Incoming message:',
          lastIncoming.from,
          '-',
          lastIncoming.body
        );

      } catch (error) {
        console.error('[AZULA] Incoming message error:', error);
      }
    });


    client.onPollResponse((data) => {
      try {
        const sender =
          data?.sender?._serialized ||
          data?.sender ||
          data?.chatId?._serialized ||
          data?.chatId ||
          null;

        let rawSelected = data?.selectedOptions;

        let currentSelections = [];

        if (Array.isArray(rawSelected)) {
          currentSelections = rawSelected
            .map((x) => {
              if (typeof x === 'string') return x;

              return (
                x?.name ||
                x?.title ||
                x?.optionName ||
                x?.text ||
                x?.id ||
                ''
              );
            })
            .map((x) => String(x || '').trim())
            .filter(Boolean);
        } else if (rawSelected && typeof rawSelected === 'object') {
          const one =
            rawSelected?.name ||
            rawSelected?.title ||
            rawSelected?.optionName ||
            rawSelected?.text ||
            rawSelected?.id ||
            '';

          if (one) {
            currentSelections = [String(one).trim()];
          }
        } else if (rawSelected) {
          currentSelections = [String(rawSelected).trim()];
        }

        const pollId = String(data?.msgId || '');
        const senderKey = String(sender || '');
        const selectionKey = pollId + '|' + senderKey;

        const previousSelections =
          latestPollSelections.get(selectionKey) || [];

        const newlySelected = currentSelections.filter(
          (choice) => !previousSelections.includes(choice)
        );

        latestPollSelections.set(selectionKey, currentSelections);

        let selected = '';

        if (newlySelected.length > 0) {
          selected = newlySelected[newlySelected.length - 1];
        } else if (currentSelections.length === 1) {
          selected = currentSelections[0];
        } else {
          return;
        }

        selected = String(selected || '').trim();

        const timestamp = Number(data?.timestamp || Math.floor(Date.now() / 1000));

        const eventId =
          'poll_' +
          pollId +
          '_' +
          String(sender || '') +
          '_' +
          selected +
          '_' +
          timestamp;

        if (!sender || !selected || processedMessageIds.has(eventId)) {
          return;
        }

        processedMessageIds.add(eventId);

        const item = {
          id: eventId,
          from: String(sender),
          to: null,
          body: selected,
          timestamp,
          detectedAt: new Date().toISOString(),
          type: 'poll_response',
          pollMessageId: pollId
        };

        incomingQueue.push(item);

        if (incomingQueue.length > 500) {
          incomingQueue = incomingQueue.slice(-500);
        }

        lastIncoming = item;

        savePersistentState();

        console.log(
          '[AZULA] Poll response captured:',
          item.from,
          '-',
          item.body
        );

      } catch (error) {
        console.error(
          '[AZULA] Poll response error:',
          error?.message || String(error)
        );
      }
    });


    client.onStateChange((state) => {
      lastStatus = state;
      console.log('[AZULA] State:', state);

      if (state === 'CONNECTED') {
        connectionStatus = 'CONNECTED';
      }
    });

  } catch (error) {
    connectionStatus = 'DISCONNECTED';
    lastError = error?.message || String(error);

    console.error('[AZULA] Connection error:', lastError);
  }
}


/*
 * Basic health check.
 */
app.get('/health', (req, res) => {
  res.json({
    ok: true,
    service: 'Azula WhatsApp Bridge'
  });
});



/*
 * Protect bridge API.
 * /health stays public.
 */
const API_KEY = process.env.AZULA_API_KEY;

function requireApiKey(req, res, next) {
  if (!API_KEY) {
    return res.status(500).json({
      success: false,
      error: 'API key is not configured'
    });
  }

  const auth = req.get('Authorization') || '';
  const expected = `Bearer ${API_KEY}`;

  if (auth !== expected) {
    return res.status(401).json({
      success: false,
      error: 'Unauthorized'
    });
  }

  next();
}

app.use(
  [
    '/status',
    '/send',
    '/send-poll',
    '/incoming-queue',
    '/reconnect',
    '/last-incoming',
    '/recent-chats',
    '/recent-messages',
    '/debug-lid',
    '/link-code'
  ],
  requireApiKey
);


/*
 * WhatsApp connection status.
 * This is what the WordPress plugin will later call.
 */
app.get('/status', (req, res) => {
  res.json({
    session: SESSION,
    status: connectionStatus,
    whatsapp_status: lastStatus,
    error: lastError,
    watchdog: {
      failures: watchdogFailures,
      recovering: watchdogRecovering,
      last_healthy_at: lastHealthyAt
    }
  });
});



/*
 * Send WhatsApp message.
 * Future WooCommerce plugin will call this endpoint.
 */
app.post('/send', async (req, res) => {
  try {
    const { phone, message } = req.body || {};

    if (!phone || !message) {
      return res.status(400).json({
        success: false,
        error: 'phone and message are required'
      });
    }

    if (!client || connectionStatus !== 'CONNECTED') {
      return res.status(503).json({
        success: false,
        error: 'WhatsApp is not connected'
      });
    }

    const cleanPhone = String(phone).replace(/\D/g, '');

    if (!cleanPhone) {
      return res.status(400).json({
        success: false,
        error: 'invalid phone number'
      });
    }

    const chatId = `${cleanPhone}@c.us`;

    const result = await withTimeout(
      client.sendText(chatId, String(message)),
      20000,
      'WhatsApp sendText'
    );

    console.log('[AZULA] Message sent to:', cleanPhone);

    return res.json({
      success: true,
      phone: cleanPhone,
      message_id: result?.id || result?.id?._serialized || null
    });

  } catch (error) {
    console.error('[AZULA] Send error:', error);

    registerSendHealthFailure(error);

    return res.status(500).json({
      success: false,
      error: error?.message || String(error)
    });
  }
});



/*
 * Last incoming WhatsApp message.
 * Temporary/test endpoint. Later this feeds WooCommerce response handling.
 */
app.get('/last-incoming', (req, res) => {
  res.json({
    success: true,
    message: lastIncoming
  });
});



/*
 * Diagnostic: read recent WhatsApp chats using the EXISTING connected client.
 */
app.get('/recent-chats', async (req, res) => {
  try {
    if (!client || connectionStatus !== 'CONNECTED') {
      return res.status(503).json({
        success: false,
        error: 'WhatsApp is not connected'
      });
    }

    const chats = await client.listChats({ onlyGroups: false });

    const recent = chats.slice(0, 10).map((chat) => ({
      id: chat?.id?._serialized || chat?.id || null,
      name: chat?.name || chat?.contact?.formattedName || '',
      unreadCount: chat?.unreadCount ?? null,
      lastMessage: chat?.lastMessage?.body || ''
    }));

    res.json({
      success: true,
      count: recent.length,
      chats: recent
    });

  } catch (error) {
    console.error('[AZULA] recent-chats error:', error);

    res.status(500).json({
      success: false,
      error: error?.message || String(error)
    });
  }
});



/*
 * Diagnostic: fetch recent messages from recent user chats.
 */
app.get('/recent-messages', async (req, res) => {
  try {
    if (!client || connectionStatus !== 'CONNECTED') {
      return res.status(503).json({
        success: false,
        error: 'WhatsApp is not connected'
      });
    }

    const chats = await client.listChats({
      onlyGroups: false,
      onlyUsers: true,
      count: 10
    });

    const result = [];

    for (const chat of chats.slice(0, 10)) {
      const chatId = chat?.id?._serialized || chat?.id;
      if (!chatId) continue;

      try {
        const messages = await client.getMessages(chatId, { count: 5 });

        result.push({
          chatId,
          name: chat?.name || chat?.contact?.formattedName || '',
          messages: messages.map((m) => ({
            id: m?.id?._serialized || m?.id || null,
            from: m?.from || null,
            to: m?.to || null,
            fromMe: m?.fromMe ?? null,
            body: m?.body || '',
            timestamp: m?.timestamp || null
          }))
        });

      } catch (error) {
        result.push({
          chatId,
          error: error?.message || String(error)
        });
      }
    }

    res.json({
      success: true,
      chats: result
    });

  } catch (error) {
    console.error('[AZULA] recent-messages error:', error);

    res.status(500).json({
      success: false,
      error: error?.message || String(error)
    });
  }
});



/*
 * Incoming queue.
 */
app.get('/incoming-queue', (req, res) => {
  res.json({
    success: true,
    count: incomingQueue.length,
    messages: incomingQueue
  });
});




/*
 * Temporary diagnostic: inspect phone -> LID mapping.
 */
app.get('/debug-lid/:phone', async (req, res) => {
  try {
    const cleanPhone = String(req.params.phone || '').replace(/\D/g, '');

    if (!cleanPhone) {
      return res.status(400).json({
        success: false,
        error: 'invalid phone number'
      });
    }

    const phoneChatId = `${cleanPhone}@c.us`;

    const result = await client.getPnLidEntry(phoneChatId);

    console.log('[AZULA] DEBUG LID:', phoneChatId, result);

    return res.json({
      success: true,
      phone: cleanPhone,
      phoneChatId,
      result
    });

  } catch (error) {
    console.error('[AZULA] DEBUG LID error:', error);

    return res.status(500).json({
      success: false,
      error: error?.message || String(error)
    });
  }
});


/*
 * Send WhatsApp poll/choice message.
 */
app.post('/send-poll', async (req, res) => {
  try {
    const { phone, title, options } = req.body || {};

    if (!phone || !title || !Array.isArray(options) || options.length < 2) {
      return res.status(400).json({
        success: false,
        error: 'phone, title and at least 2 options are required'
      });
    }

    const cleanPhone = String(phone).replace(/\D/g, '');

    if (!cleanPhone) {
      return res.status(400).json({
        success: false,
        error: 'invalid phone number'
      });
    }

    if (!client || connectionStatus !== 'CONNECTED') {
      return res.status(503).json({
        success: false,
        error: 'WhatsApp is not connected'
      });
    }

    const phoneChatId = `${cleanPhone}@c.us`;
    let chatId = phoneChatId;

    try {
      const lidEntry = await withTimeout(
        client.getPnLidEntry(phoneChatId),
        7000,
        'WhatsApp LID lookup'
      );

      const resolvedLid =
        lidEntry?.lid?._serialized ||
        lidEntry?.lid ||
        lidEntry?.lidJid?._serialized ||
        lidEntry?.lidJid ||
        null;

      if (resolvedLid) {
        chatId = String(resolvedLid);
        console.log('[AZULA] Poll LID resolved:', cleanPhone, '->', chatId);
      } else {
        console.log('[AZULA] No LID returned, using:', phoneChatId);
      }
    } catch (lidError) {
      if (isBrowserHealthError(lidError)) {
        throw lidError;
      }

      console.log(
        '[AZULA] LID lookup unavailable, using phone JID:',
        lidError?.message || String(lidError)
      );
    }

    const result = await withTimeout(
      client.sendPollMessage(
        chatId,
        String(title),
        options.map((x) => String(x)),
        {
          selectableCount: 1
        }
      ),
      15000,
      'WhatsApp sendPollMessage'
    );

    console.log('[AZULA] Poll sent to:', cleanPhone);

    return res.json({
      success: true,
      phone: cleanPhone,
      message_id: result?.id?._serialized || result?.id || null
    });

  } catch (error) {
    console.error('[AZULA] Poll send error:', error);

    registerSendHealthFailure(error);

    return res.status(500).json({
      success: false,
      error: error?.message || String(error)
    });
  }
});



/*
 * Return the latest WhatsApp linking code.
 */
app.all('/link-code', async (req, res) => {
  try {
    if (connectionStatus === 'CONNECTED') {
      return res.json({
        success: true,
        alreadyConnected: true,
        code: null,
        generatedAt: null,
        status: connectionStatus,
        message: 'WhatsApp is already connected'
      });
    }

    const phone = String(
      req.body?.phone ||
      req.query?.phone ||
      ''
    ).replace(/\D/g, '');

    if (!phone) {
      return res.status(400).json({
        success: false,
        error: 'WhatsApp phone number is required'
      });
    }

    lastLinkCode = null;
    lastLinkCodeAt = null;

    const oldClient = client;
    client = null;

    if (oldClient) {
      try {
        await oldClient.close();
      } catch (error) {
        console.log(
          '[AZULA] Link-code client close warning:',
          error?.message || String(error)
        );
      }
    }

    connectionStatus = 'CONNECTING';

    client = await wppconnect.create({
      session: SESSION,
      headless: true,
      logQR: false,
      autoClose: 0,

      puppeteerOptions: {
        protocolTimeout: 120000
      },

      phoneNumber: phone,

      browserArgs: [
        '--no-sandbox',
        '--disable-setuid-sandbox',
        '--disable-dev-shm-usage'
      ],

      statusFind: (status) => {
        lastStatus = status;

        if (
          status === 'inChat' ||
          status === 'isLogged' ||
          status === 'qrReadSuccess'
        ) {
          connectionStatus = 'CONNECTED';
        }

        if (
          status === 'notLogged' ||
          status === 'browserClose' ||
          status === 'qrReadError' ||
          status === 'serverClose'
        ) {
          connectionStatus = 'DISCONNECTED';
        }
      },

      catchLinkCode: (code) => {
        lastLinkCode = code;
        lastLinkCodeAt = new Date().toISOString();
        console.log('[AZULA] New linking code generated');
      }
    });

    return res.json({
      success: true,
      alreadyConnected: false,
      code: lastLinkCode,
      generatedAt: lastLinkCodeAt,
      status: connectionStatus
    });

  } catch (error) {
    connectionStatus = 'DISCONNECTED';
    lastError = error?.message || String(error);

    return res.status(500).json({
      success: false,
      error: lastError
    });
  }
});


/*
 * Manual reconnect endpoint.
 */
app.post('/reconnect', async (req, res) => {
  if (watchdogRecovering) {
    return res.status(409).json({
      success: false,
      error: 'WhatsApp recovery is already in progress'
    });
  }

  recoverWhatsApp('Manual reconnect requested').catch((error) => {
    console.error(
      '[AZULA] Manual reconnect recovery error:',
      error?.message || String(error)
    );
  });

  return res.json({
    success: true,
    message: 'Hardened reconnect started'
  });
});


loadPersistentState();

app.listen(PORT, '0.0.0.0', () => {
  console.log(`[AZULA] Bridge listening on port ${PORT}`);

  connectWhatsApp();
  startWhatsAppWatchdog();
});
