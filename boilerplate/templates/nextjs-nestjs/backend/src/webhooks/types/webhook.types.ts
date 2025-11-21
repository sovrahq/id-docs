export interface Payload<T = any> {
  eventType: string;
  eventData: T;
  eventWebhookResponse?: EventWebhookResponseType;
}

export type EventWebhookResponseType = any;

