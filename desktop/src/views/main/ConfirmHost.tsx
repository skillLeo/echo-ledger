// The window's one confirm dialog (lib/confirm.ts): mounted once at the main window's root.
import { useEffect, useState } from "react";
import { hostConfirm, type PendingQuestion } from "../../lib/confirm";
import { ConfirmDialog } from "./SettingsControls";

export function ConfirmHost() {
  const [question, setQuestion] = useState<PendingQuestion | null>(null);
  useEffect(() => hostConfirm(setQuestion), []);
  if (!question) return null;
  return (
    <ConfirmDialog title={question.title} body={question.body} confirm={question.confirm} cancel={question.cancel}
      onConfirm={() => question.answer(true)} onClose={() => question.answer(false)} />
  );
}
