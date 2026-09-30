import { readdir, readFile } from 'node:fs/promises'
import { extname, join, relative } from 'node:path'

const mode = process.argv[2] || 'all'
const roots = []
if (mode === 'source' || mode === 'all') roots.push('src')
if (mode === 'build' || mode === 'all') roots.push('../assets/dashboard')

const allowedExtensions = new Set(['.vue', '.js', '.mjs', '.cjs', '.ts', '.css', '.json', '.html'])
const suspicious = [
  '\u251c', '\u252c', '\ufffd', '\u00c2',
  '\u00c3\u00a1', '\u00c3\u00a9', '\u00c3\u00ad', '\u00c3\u00b3', '\u00c3\u00ba', '\u00c3\u00b1',
  '\u00e2\u20ac',
]

async function walk(dir) {
  const entries = await readdir(dir, { withFileTypes: true })
  const files = []
  for (const entry of entries) {
    const full = join(dir, entry.name)
    if (entry.isDirectory()) files.push(...await walk(full))
    else if (allowedExtensions.has(extname(entry.name))) files.push(full)
  }
  return files
}

const failures = []
for (const root of roots) {
  let files = []
  try { files = await walk(root) } catch { continue }
  for (const file of files) {
    const value = await readFile(file, 'utf8')
    for (const marker of suspicious) {
      if (value.includes(marker)) failures.push(relative(process.cwd(), file))
    }
  }
}

if (failures.length > 0) {
  console.error('ENCODING_QA=FAIL')
  for (const file of [...new Set(failures)]) console.error('- ' + file)
  process.exit(1)
}

console.log('ENCODING_QA=OK mode=' + mode)
