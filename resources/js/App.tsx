import { Route, Routes } from 'react-router-dom';
import { AuthForm } from './components/AuthForm';
import { StudioShell } from './components/StudioShell';
export default function App() {
    return (
        <Routes>
            <Route path="/login" element={<AuthForm key="login" />} />
            <Route
                path="/forgot-password"
                element={<AuthForm key="forgot" mode="forgot" />}
            />
            <Route
                path="/reset-password"
                element={<AuthForm key="reset" mode="reset" />}
            />
            <Route path="*" element={<StudioShell />} />
        </Routes>
    );
}
