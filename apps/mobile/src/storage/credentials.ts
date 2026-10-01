import * as SecureStore from 'expo-secure-store';
export interface Credentials { read(): Promise<string | null>; write(token: string): Promise<void>; clear(): Promise<void> }
const key = 'gt.member.token';
const options = {keychainAccessible: SecureStore.WHEN_UNLOCKED_THIS_DEVICE_ONLY};
// Serialize writes/deletes so a delayed login write cannot undo logout.
let queue: Promise<unknown> = Promise.resolve();
function serialize<T>(operation: () => Promise<T>): Promise<T> {
  const next = queue.then(operation, operation);
  queue = next.catch(() => undefined);
  return next;
}
export const credentials: Credentials = {
  read: () => serialize(() => SecureStore.getItemAsync(key, options)),
  write: (token) => serialize(() => SecureStore.setItemAsync(key, token, options)),
  clear: () => serialize(() => SecureStore.deleteItemAsync(key, options)),
};

const staffKey = 'gt.staff.token';
export const staffCredentials: Credentials = {
 read: () => serialize(() => SecureStore.getItemAsync(staffKey, options)),
 write: token => serialize(() => SecureStore.setItemAsync(staffKey, token, options)),
 clear: () => serialize(() => SecureStore.deleteItemAsync(staffKey, options)),
};
