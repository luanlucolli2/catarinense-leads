export type PhoneListSummary = {
  validNumbers: string[]
  invalidTokens: string[]
  duplicateCount: number
}

export function parseBrazilianMobilePhoneList(value: string): PhoneListSummary {
  const validNumbers: string[] = []
  const invalidTokens: string[] = []
  const seenNumbers = new Set<string>()
  let duplicateCount = 0

  for (const token of value.split(/[\n,;]+/)) {
    const trimmedToken = token.trim()
    if (!trimmedToken) {
      continue
    }

    let digits = trimmedToken.replace(/\D/g, '')
    if (digits.length === 13 && digits.startsWith('55')) {
      digits = digits.slice(2)
    }

    if (!/^[1-9]{2}9\d{8}$/.test(digits)) {
      invalidTokens.push(trimmedToken)
      continue
    }

    if (seenNumbers.has(digits)) {
      duplicateCount++
      continue
    }

    seenNumbers.add(digits)
    validNumbers.push(digits)
  }

  return { validNumbers, invalidTokens, duplicateCount }
}
