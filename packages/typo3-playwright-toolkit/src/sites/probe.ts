import { createHmac } from 'node:crypto'
import { PROBE_HEADER, TEST_ID_HEADER } from '../contract.js'
import type { DiscoveredSite, SiteEntry } from './registry.js'

const PURPOSE = 'probe'

export function probeSignature(secret: string, testId: string): string {
    return createHmac('sha256', secret).update(`${PURPOSE}:${testId}`).digest('hex')
}

export async function probeSite(
    site: DiscoveredSite,
    options: { testId: string; secret: string; fetchImpl?: typeof fetch },
): Promise<SiteEntry> {
    const unavailable = (reason: string): SiteEntry => ({ ...site, available: false, reason })

    let response: Response
    try {
        response = await (options.fetchImpl ?? fetch)(site.base, {
            method: 'GET',
            redirect: 'manual',
            signal: AbortSignal.timeout(Number(process.env.PW_HEALTH_TIMEOUT_MS) || 5000),
            headers: {
                [TEST_ID_HEADER]: options.testId,
                [PROBE_HEADER]: probeSignature(options.secret, options.testId),
            },
        })
    } catch (error) {
        return unavailable(error instanceof Error ? error.message : String(error))
    }

    if (response.status >= 300 && response.status < 400) {
        return unavailable(`it redirects to ${response.headers.get('location') ?? '(no location)'}`)
    }
    if (response.status >= 500) {
        return unavailable(`it answered ${response.status}`)
    }

    const answer = await readAnswer(response)
    if (undefined === answer || answer.testId !== options.testId) {
        return unavailable('no toolkit answered: the host is not in the Testing context, or it is another installation')
    }
    if ('string' !== typeof answer.database) {
        return unavailable('another installation answered: the test database is not selected there')
    }
    if (answer.site !== site.identifier) {
        return unavailable(`this host serves the site "${String(answer.site)}"`)
    }

    return { ...site, available: true }
}

async function readAnswer(response: Response): Promise<Record<string, unknown> | undefined> {
    try {
        return (await response.json()) as Record<string, unknown>
    } catch {
        return undefined
    }
}
