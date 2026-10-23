import { getToolkitConfig } from '../config.js'
import { toolkitHeaders } from '../contract.js'

export interface JsonPoster {
    post(
        url: string,
        options: { headers: Record<string, string>; data: unknown },
    ): Promise<{ status(): number; text(): Promise<string> }>
}

export async function saveSiteSettings(
    poster: JsonPoster,
    context: { baseUrl: string; backendPath: string; testId: string },
    site: string,
    settings: Record<string, unknown>,
): Promise<void> {
    if ('' === site) {
        throw new Error(
            '[typo3-playwright-toolkit] Site settings need the site TYPO3 resolves, and this run discovered none. ' +
                'sites.json in the run directory says why.',
        )
    }

    const response = await poster.post(`${context.baseUrl}${context.backendPath}/test-api/site-settings`, {
        headers: toolkitHeaders(getToolkitConfig(), context.testId),
        data: { site, settings },
    })

    if (200 === response.status()) {
        return
    }

    throw new Error(
        `[typo3-playwright-toolkit] Site settings for "${site}" were not saved ` +
            `(status ${response.status()}): ${reasonIn(await response.text())}`,
    )
}

function reasonIn(body: string): string {
    try {
        const parsed = JSON.parse(body) as { error?: unknown }

        return 'string' === typeof parsed.error ? parsed.error : body
    } catch {
        return body
    }
}
