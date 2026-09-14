/**
 * Mirrors the CORE-03 Task 1 backend contract exactly (Modules\AI\Presentation\
 * Http\Requests\AssistantMessageRequest / Modules\AI\Application\ValueObjects\
 * AIAssistantResponse). No field here is invented.
 */

export type AssistantEntityReference = {
  type: string;
  id: string;
  label: string;
  route: string;
};

export type AssistantResponseStatus = 'ok' | 'denied' | 'unavailable' | 'tool_limit_reached';

export type AssistantApiResponse = {
  status: AssistantResponseStatus;
  message: string | null;
  references: AssistantEntityReference[];
};

/** One turn of the bounded, client-resent recent history (§9/§26 — no server persistence). */
export type AssistantHistoryTurn = {
  role: 'user' | 'assistant';
  content: string;
};

/** The bounded, approved context hints §6/§7 allow — never full page state. */
export type AssistantContextHints = {
  route?: string;
  module?: string;
  page?: string;
  entity_type?: string;
  entity_id?: string;
  brand_id?: string;
};

export type AssistantMessageRequestPayload = AssistantContextHints & {
  message: string;
  history?: AssistantHistoryTurn[];
};

/** One rendered turn in the drawer's own local conversation state. */
export type AssistantConversationTurn = {
  id: string;
  role: 'user' | 'assistant';
  content: string;
  status?: AssistantResponseStatus;
  references?: AssistantEntityReference[];
};
