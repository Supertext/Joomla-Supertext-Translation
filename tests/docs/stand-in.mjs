#!/usr/bin/env node
/**
 * Docs screenshots and CI only: answers like the Supertext AI file translation API v1 and
 * returns German, French and Italian for the demo's sample articles (samples.json: real
 * Supertext output, keyed by target language's primary subtag).
 * Unknown text comes back as "[<target>] text". Listens on :8765 (any API key).
 *
 * Accepts exactly the languages of the live API (the SDK's Languages list, October 2026):
 * an unknown pair gets HTTP 404 INVALID_LANGUAGE_PAIR, formal/informal for a target without
 * it HTTP 400 FEATURE_ERROR. Also stands in for the Commando Center: POST /report answers 202
 * and logs the report (SUPERTEXT_REPORT_URL=http://127.0.0.1:8765/report), so tests and
 * screenshots never report to the real center.
 */
import http from 'node:http'
import { randomBytes } from 'node:crypto'
import { readFileSync } from 'node:fs'

const norm = (s) => s.replace(/\s+/g, ' ').replace(/> </g, '><').trim()
const decode = (s) => s.replace(/&quot;/g, '"').replace(/&#0?39;|&apos;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&')
const encode = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
const samples = Object.fromEntries(
  Object.entries(JSON.parse(readFileSync(new URL('samples.json', import.meta.url), 'utf8'))).map(([lang, pairs]) => [
    lang,
    new Map(Object.entries(pairs).map(([k, v]) => [norm(k), v])),
  ]),
)
const files = new Map()
const SOURCES = new Set('bg cs da de el en es fi fr gsw hr hu it ja ko nb nl pl pt rm ru sk sl sq sr sv tr zh'.split(' '))
const TARGETS = new Set(('bg cs da de-AT de-CH de-DE el en-GB en-US es fi fr-CH fr-FR gsw-u-sd-chbe gsw-u-sd-chzh hr hu it-CH it-IT ja ko nb nl pl ' +
  'pt-BR pt-PT rm ru sk sl sq sr-Cyrl sr-Latn sv tr zh-Hans zh-Hant').split(' '))
const NO_POLITENESS = new Set('en-GB en-US gsw-u-sd-chbe gsw-u-sd-chzh rm sq sr-Cyrl sr-Latn'.split(' '))

http
  .createServer(async (req, res) => {
    const path = new URL(req.url, 'http://x').pathname.replace(/^\/v1/, '')
    const send = (status, body, type = 'application/json') => {
      res.writeHead(status, { 'Content-Type': type })
      res.end(type === 'application/json' ? JSON.stringify(body) : body)
    }
    if (req.method === 'POST' && path === '/report') {
      const chunks = []
      for await (const chunk of req) chunks.push(chunk)
      console.log(`report ${Buffer.concat(chunks).toString('utf8')}`)
      return send(202, { ok: true })
    }
    if (!/^Supertext-Auth-Key \S+$/.test(req.headers.authorization || '')) return send(401, { detail: 'missing key' })
    if (path === '/features') return send(200, { supported_features: [] })
    if (req.method === 'POST') {
      const chunks = []
      for await (const chunk of req) chunks.push(chunk)
      const form = await new Request('http://x', { method: 'POST', headers: req.headers, body: Buffer.concat(chunks) }).formData()
      const file = form.get('file')
      if (!file || file.type !== 'text/html') return send(415, { detail: 'FILETYPE_NOT_ALLOWED' })
      // Like the real API: only its exact codes (es, not es-ES; de-CH, not de), bare sources.
      const target = String(form.get('target_lang') ?? '')
      const source = String(form.get('source_lang') ?? '')
      if (!TARGETS.has(target) || (source !== '' && !SOURCES.has(source)) || source === target.split('-')[0]) {
        return send(404, { error_code: 'INVALID_LANGUAGE_PAIR', source_lang: source, target_lang: target })
      }
      if (['more', 'less'].includes(String(form.get('politeness') ?? '')) && NO_POLITENESS.has(target)) {
        return send(400, { error_code: 'FEATURE_ERROR', feature: 'politeness', source_lang: source, target_lang: target })
      }
      const id = randomBytes(6).toString('hex')
      files.set(id, { html: await file.text(), target: String(form.get('target_lang')) })
      return send(200, { file_id: id })
    }
    const match = path.match(/file\/([a-f0-9]+)(\/status|\/translation)?$/)
    const file = match && files.get(match[1])
    if (!file) return send(404, {})
    if (req.method === 'DELETE') { files.delete(match[1]); return send(200, {}) }
    if (match[2] === '/status') return send(200, { status: 'done' })
    const known = samples[file.target.split('-')[0]] ?? new Map()
    const html = file.html.replace(/(<div data-st-id="\d+">)([\s\S]*?)(<\/div>\n)/g, (all, open, inner, close) => {
      const hit = known.get(norm(inner)) ?? known.get(norm(decode(inner)))
      if (hit !== undefined) return open + (known.has(norm(inner)) ? hit : encode(hit)) + close
      return open + inner.replace(/>([^<]+)</g, (m, t) => (t.trim() ? `>[${file.target}] ${t}<` : m)).replace(/^([^<]+)/, `[${file.target}] $1`) + close
    })
    send(200, html, 'text/html')
  })
  .listen(Number(process.env.PORT || 8765), '127.0.0.1', () => console.log(`Stand-in API on http://127.0.0.1:${process.env.PORT || 8765}/v1/`))
