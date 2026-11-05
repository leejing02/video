// Timer, socket and foreground events share the same recovery request.
export function singleFlight(task: () => Promise<void>) {
  let running: Promise<void> | undefined
  return () => {
    running ??= Promise.resolve().then(task).finally(() => { running = undefined })
    return running
  }
}
